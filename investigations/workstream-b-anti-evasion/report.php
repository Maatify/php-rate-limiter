<?php

declare(strict_types=1);

// Gate evaluation + summary generator for Workstream B / Anti-Evasion. Reads results/actual/<scenario>.<variant>.jsonl produced by
// run.sh for S0, A1, PW, P2 and writes:
//   <out>/gates.jsonl   machine-readable gate results (one row per variant x gate) — golden-diffed by run.sh
//   <out>/summary.md    human-readable tables generated from the SAME data (no hand-typed numbers)
// Gate vocabulary: PASS | FAIL | UNPROVEN | NOT_APPLICABLE. Thresholds are NEVER invented: every comparison is against S0 or against
// a stated contract; where only an Owner could choose a limit the row is flagged ownerDecision=true.
require __DIR__ . '/fixtures/anti_evasion.php';

$dir = $argv[1] ?? (__DIR__ . '/results/actual');
$out = $argv[2] ?? $dir;
$variants = ['S0', 'A1', 'PW', 'P2', 'RL'];
$candidates = ['A1', 'PW', 'P2', 'RL'];

/** @return list<array<string, mixed>> */
function rows(string $dir, string $scenario, string $variant): array
{
    $f = "$dir/$scenario.$variant.jsonl";
    if (! is_file($f)) {
        return [];
    }
    $r = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $r;
}

$M = []; // [variant][stream][shape][flow] => row
$A = []; // [variant][scenario-prefix] => rows
foreach ($variants as $v) {
    foreach (rows($dir, 'a01_matrix', $v) as $r) {
        if ($r['s'] === 'A01.matrix') {
            $M[$v][$r['stream']][$r['shape']][$r['flow']] = $r;
        }
    }
    foreach (['a02_boundaries', 'a03_rotation_ephemeral_backend', 'a04_state_bounds', 'a05_redis', 'a06_indistinguishability'] as $sc) {
        foreach (rows($dir, $sc, $v) as $r) {
            $A[$v][$sc][] = $r;
        }
    }
}
$assertOk = static function (array $rows, string $needle) use (&$A): ?bool {
    foreach ($rows as $r) {
        if (($r['assert'] ?? null) !== null && str_contains($r['assert'], $needle)) {
            return (bool) $r['ok'];
        }
    }

    return null;
};
$gate = [];
$emitGate = static function (string $variant, string $gateId, string $status, string $evidence, array $extra = []) use (&$gate): void {
    $gate[] = ['variant' => $variant, 'gate' => $gateId, 'status' => $status] + $extra + ['evidence' => $evidence];
};
$get = static fn(string $v, string $stream, string $shape, string $flow): ?array => $M[$v][$stream][$shape][$flow] ?? null;

$evasionStreams = array_values(array_filter(wsbPrimaryOrder(), static fn(string $id): bool => in_array(wsbClass($id), ['evasion', 'both'], true)));
$poisonPaid = ['X6', 'Y4c', 'Y4e', 'Y4f', 'Y6c'];     // attacker pays only free failures
$benign = ['L1', 'L1f', 'L2', 'L3', 'L4'];

foreach ($candidates as $v) {
    // ---- A. Contract MUST-PRESERVE ----------------------------------------------------------------------------------------------
    $a = $A[$v]['a03_rotation_ephemeral_backend'] ?? [];
    $rot = $assertOk($a, 'current/previous rotation keeps the evidence/count continuous');
    $emitGate($v, 'A.1 rotation continuity (DECISION_MATRIX §5.0, KEY_STRATEGY: previous state must not become a current-only reset)', $rot === null ? 'UNPROVEN' : ($rot ? 'PASS' : 'FAIL'), 'A03.R1 pair split across an outer-key rotation must still be convicted at the third member');

    $b = $A[$v]['a02_boundaries'] ?? [];
    $watch = static function (array $rows, string $flow, int $t): ?bool {
        foreach ($rows as $r) {
            if ($r['s'] === 'A02.B3.watch-boundary' && $r['flow'] === $flow && $r['secondPairEndsAt'] === $t) {
                return $r['blockedAtSecondPairEnd'];
            }
        }

        return null;
    };
    $s0w = [$watch($A['S0']['a02_boundaries'] ?? [], 'out', 1800), $watch($A['S0']['a02_boundaries'] ?? [], 'out', 1801)];
    $vw = [$watch($b, 'out', 1800), $watch($b, 'out', 1801)];
    $emitGate($v, 'A.2 near-threshold WATCH keeps blocking "hover at N-1" (DECISION_MATRIX §5.0)', $s0w === $vw ? 'PASS' : 'FAIL', 'A02.B3 record-only: S0=' . json_encode($s0w) . ' variant=' . json_encode($vw) . ' (second pair ending at 1800 / 1801 s)', ['ownerDecision' => $s0w !== $vw]);

    $f1a = $assertOk($a, 'F1a.churn-set-write');
    $f1b = $assertOk($a, 'F1b.witness-write');
    $emitGate($v, 'A.3 backend failures propagate (never ALLOW / silent skip)', ($f1a === true && ($f1b === null || $f1b === true)) ? 'PASS' : 'FAIL', 'A03.F1a=' . json_encode($f1a) . ' F1b=' . json_encode($f1b));

    $capFail = 0;
    foreach (($A[$v]['a04_state_bounds'] ?? []) as $r) {
        if (isset($r['assert']) && ! $r['ok']) {
            $capFail++;
        }
    }
    $redis = $A[$v]['a05_redis'] ?? [];
    $redisFail = 0;
    foreach ($redis as $r) {
        if (isset($r['assert']) && ! $r['ok'] && ! str_starts_with($r['assert'], 'R4')) {
            $redisFail++;
        }
    }
    $emitGate($v, 'A.4 bounded storage: declared caps, TTL on every key, no key explosion beyond linear', ($capFail === 0 && $redisFail === 0) ? 'PASS' : 'FAIL', "A04 failed assertions=$capFail, A05 (R1-R3,R5) failed assertions=$redisFail");

    $r4 = $assertOk($redis, 'R4:');
    $emitGate($v, 'A.5 atomic evidence under concurrency (real Redis, 8 concurrent writers)', $v === 'RL' ? 'UNPROVEN' : ($r4 === null ? 'NOT_APPLICABLE' : ($r4 ? 'PASS' : 'FAIL')), $v === 'RL' ? 'RL latch races (a duplicate mis-flagged as fresh defeats the latch, i.e. fails toward S0) need a post-expiry concurrent test; Redis owns time so it cannot be driven deterministically here' : 'A05.R4 duplicate-member race', ['ownerDecision' => $r4 === false, 'note' => 'requires an atomic "added" signal = store-contract change if it must be fixed']);

    $cov = [];
    foreach ([['X2', 'login', 'out'], ['X2', 'login', 'pre'], ['X2', 'api', 'out'], ['X1', 'api', 'out']] as [$st, $sh, $fl]) {
        $r0 = $get('S0', $st, $sh, $fl);
        $rv = $get($v, $st, $sh, $fl);
        if ($r0 !== null && $rv !== null) {
            $cov["$st/$sh/$fl"] = ['S0' => $r0['attackDenied'] . '/' . $r0['attackAttempts'], $v => $rv['attackDenied'] . '/' . $rv['attackAttempts']];
        }
    }
    $lost = array_filter($cov, static fn(array $x): bool => (int) explode('/', $x[$v])[0] < (int) explode('/', $x['S0'])[0]);
    $emitGate($v, 'A.6 Public Command Lifecycle coverage kept (record-only, check-only, API_OVERUSE recordSuccess-only hosts)', $lost === [] ? 'PASS' : 'FAIL', json_encode($cov), ['lostIn' => array_keys($lost)]);

    $patch = (string) file_get_contents(__DIR__ . '/patches/wsb-candidates.patch');
    $touchesPersistence = preg_match('/^[+-].*(blockWithCycleTracking|persistWinningCandidates|checkActiveBlocks|buildKeys)/m', $patch) === 1;
    $emitGate($v, 'A.7 DEC-003 cycle tracking / active-block ordering / key derivation untouched', $touchesPersistence ? 'FAIL' : 'PASS', 'patch hunks reference none of blockWithCycleTracking / persistWinningCandidates / checkActiveBlocks / buildKeys (static check of patches/wsb-candidates.patch)');

    $emitGate($v, 'A.8 normative DECISION_MATRIX §5.3 text unchanged', 'FAIL', 'every candidate changes WHEN a triggered §5.3 rule enforces (evidence gating / LOW exemption); adopting it needs a contract version bump', ['ownerDecision' => true]);

    // ---- B. Security regression vs S0 -------------------------------------------------------------------------------------------
    // pre+out / pre: an attacker attempt "reaches verification" when checkOnly lets it through, so MORE reach than S0 = lost protection.
    // out (record-only Host): the Host has verified every attempt before recording, so reach is always full; the protection is the
    // denial signal itself, so FEWER denied attacker attempts than S0 = lost protection.
    $regress = [];
    $worst = 0;
    foreach ($evasionStreams as $st) {
        foreach (['login' => ['pre+out', 'out', 'pre'], 'login-anon' => ['pre+out', 'out'], 'otp' => ['pre+out', 'out'], 'api' => ['pre+out', 'out'], 'api_custom' => ['pre+out', 'out']] as $shape => $flows) {
            foreach ($flows as $fl) {
                $r0 = $get('S0', $st, $shape, $fl);
                $rv = $get($v, $st, $shape, $fl);
                if ($r0 === null || $rv === null) {
                    continue;
                }
                if ($fl === 'out') {
                    $d = $r0['attackDenied'] - $rv['attackDenied'];
                    if ($d > 0) {
                        $regress["$st/$shape/$fl"] = ['metric' => 'attackerDenied', 'S0' => $r0['attackDenied'], $v => $rv['attackDenied'], 'of' => $rv['attackAttempts']];
                        $worst = max($worst, $d);
                    }
                } else {
                    $d = $rv['attackReachedVerification'] - $r0['attackReachedVerification'];
                    if ($d > 0) {
                        $regress["$st/$shape/$fl"] = ['metric' => 'attackerReach', 'S0' => $r0['attackReachedVerification'], $v => $rv['attackReachedVerification'], 'of' => $rv['attackAttempts']];
                        $worst = max($worst, $d);
                    }
                }
            }
        }
    }
    $emitGate($v, 'B. security regression: evasion traffic that S0 stops but the variant lets through', $regress === [] ? 'PASS' : 'FAIL', count($regress) . ' (stream/shape/flow) cells with weaker protection than S0; worst delta ' . $worst . ' attacker attempts', ['cells' => count($regress), 'worstDelta' => $worst, 'ownerDecision' => $regress !== [], 'detail' => $regress]);

    // ---- C. Legitimate-user fairness -------------------------------------------------------------------------------------------
    $benefit = [];
    foreach ($benign as $st) {
        $r0 = $get('S0', $st, 'login', 'pre+out');
        $rv = $get($v, $st, 'login', 'pre+out');
        $benefit[$st] = ['S0' => $r0['legitDenied'] . '/' . $r0['legitAttempts'], $v => $rv['legitDenied'] . '/' . $rv['legitAttempts'], 'viaCorrelation' => $rv['legitDeniedByCorrelation']];
    }
    $restored = [];
    foreach ($poisonPaid as $st) {
        foreach (['pre+out', 'out'] as $fl) {
            $rv = $get($v, $st, 'login', $fl);
            $r0 = $get('S0', $st, 'login', $fl);
            if ($rv !== null && $rv['legitDeniedByCorrelation'] > 0) {
                $restored["$st/$fl"] = ['S0' => $r0['legitDeniedByCorrelation'] . '/' . $r0['legitAttempts'], $v => $rv['legitDeniedByCorrelation'] . '/' . $rv['legitAttempts']];
            }
        }
    }
    $x8 = $get($v, 'X8', 'login', 'pre+out');
    if ($x8 !== null && $x8['legitDeniedByCorrelation'] > 0) {
        $restored['X8/login/pre+out'] = ['S0' => $get('S0', 'X8', 'login', 'pre+out')['legitDeniedByCorrelation'] . '/50', $v => $x8['legitDeniedByCorrelation'] . '/50'];
    }
    $emitGate($v, 'C. fairness: legitimate users denied by correlation while the attacker pays only free failures / botnet traffic', $restored === [] ? 'PASS' : 'FAIL', count($restored) . ' poisoning cells still deny legitimate users through correlation', ['cells' => count($restored), 'ownerDecision' => $restored !== [], 'benignBenefit' => $benefit, 'detail' => $restored]);

    // C2 (information): user-visible denial totals under paid poisoning, correlation-attributable or not (K1 score / credential spray
    // collateral is outside every candidate's reach and is reported, not hidden).
    $tot = [];
    foreach ($poisonPaid as $st) {
        foreach (['pre+out', 'out'] as $fl) {
            $r0 = $get('S0', $st, 'login', $fl);
            $rv = $get($v, $st, 'login', $fl);
            if ($r0 !== null && $rv !== null) {
                $tot["$st/$fl"] = ['S0' => $r0['legitDenied'] . '/' . $r0['legitAttempts'], $v => $rv['legitDenied'] . '/' . $rv['legitAttempts'], 'controlNoCorrelation' => $rv['controlLegitDenied']];
            }
        }
    }
    $gate[] = ['variant' => $v, 'gate' => 'C2 user-visible legitimate denials under paid poisoning (all causes)', 'status' => 'INFO', 'evidence' => 'controlNoCorrelation = denials that remain with churn/dilution correlation disabled (K1 score / credential-spray collateral)', 'detail' => $tot];

    // ---- D. Residual threats (undetected by BOTH S0 and the variant) ------------------------------------------------------------
    $resid = [];
    foreach ($evasionStreams as $st) {
        $r0 = $get('S0', $st, 'login', 'pre+out');
        $rv = $get($v, $st, 'login', 'pre+out');
        if ($r0 !== null && $rv !== null && $r0['attackAttempts'] > 0 && $r0['attackReachedVerification'] === $r0['attackAttempts'] && $rv['attackReachedVerification'] === $rv['attackAttempts']) {
            $resid[] = $st;
        }
    }
    $emitGate($v, 'D. residual: attack streams that fully reach verification under S0 AND the variant', 'PASS', 'residual (not separable from the available signals): ' . implode(', ', $resid), ['residualStreams' => $resid, 'note' => 'status PASS = analysis complete, not "no residual threat"']);
}

$ind = $A['S0']['a06_indistinguishability'] ?? [];
$indOk = $ind !== [] && count(array_filter($ind, static fn(array $r): bool => isset($r['assert']) && ! $r['ok'])) === 0;
$gate[] = ['variant' => 'ALL', 'gate' => 'D2 indistinguishability: attacker and legitimate streams with identical observable tuples', 'status' => $indOk ? 'PASS' : 'FAIL', 'evidence' => count(array_filter($ind, static fn(array $r): bool => $r['s'] === 'A06.pair')) . ' pairs with equal observable digests receive identical treatment on every variant (no policy over these inputs can separate them)'];

// ---- Offline prediction vs runtime fidelity (PR #103 model vs the experimental runtime) -------------------------------------------
$modelFile = dirname(__DIR__) . '/workstream-b-correlation/expected/model_matrix.jsonl';
$fidelity = [];
if (is_file($modelFile)) {
    foreach (file($modelFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $m = json_decode($l, true);
        if (($m['flow'] ?? '') !== 'pre+out' || ! in_array($m['policy'], ['A1', 'PW', 'P2'], true)) {
            continue;
        }
        $id = explode(' ', $m['stream'])[0];
        $rv = $get($m['policy'], $id, 'login', 'pre+out');
        if ($rv === null) {
            continue;
        }
        $same = $m['legitBlocked'] === $rv['legitDenied'] . '/' . $rv['legitAttempts'] && $m['attackBlocked'] === $rv['attackDenied'] . '/' . $rv['attackAttempts'] && $m['attackReachedVerification'] === $rv['attackReachedVerification'];
        $fidelity[$m['policy']][$id] = ['model' => "{$m['legitBlocked']} {$m['attackBlocked']} reach{$m['attackReachedVerification']}", 'runtime' => "{$rv['legitDenied']}/{$rv['legitAttempts']} {$rv['attackDenied']}/{$rv['attackAttempts']} reach{$rv['attackReachedVerification']}", 'same' => $same];
    }
}
foreach ($fidelity as $p => $rowsF) {
    $diff = array_keys(array_filter($rowsF, static fn(array $x): bool => ! $x['same']));
    $gate[] = ['variant' => $p, 'gate' => 'offline-vs-runtime fidelity (PR #103 model prediction vs experimental runtime, login pre+out)', 'status' => 'INFO', 'evidence' => count($rowsF) . ' streams compared; identical=' . (count($rowsF) - count($diff)) . '; different=' . implode(',', $diff), 'detail' => $rowsF];
}

@mkdir($out, 0777, true);
$fh = fopen("$out/gates.jsonl", 'w');
foreach ($gate as $g) {
    fwrite($fh, json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
fclose($fh);

// ---- summary.md ------------------------------------------------------------------------------------------------------------------
$md = "# Generated summary (do not edit; produced by report.php from results/actual)\n\n";
$md .= "## Gate status per candidate\n\n| Gate | " . implode(' | ', $candidates) . " |\n|---|" . str_repeat('---|', count($candidates)) . "\n";
$byGate = [];
foreach ($gate as $g) {
    if (in_array($g['variant'], $candidates, true) && $g['status'] !== 'INFO') {
        $byGate[$g['gate']][$g['variant']] = $g['status'] . (! empty($g['ownerDecision']) ? ' (owner)' : '');
    }
}
foreach ($byGate as $name => $st) {
    $md .= '| ' . $name . ' | ' . implode(' | ', array_map(static fn(string $c): string => $st[$c] ?? '-', $candidates)) . " |\n";
}
$md .= "\n## Attack-vs-legitimate matrix (Login with account id, flow pre+out)\n\nCell = `legit denied/legit attempts (of which attributable to correlation)` · `attacker denied/attempts` · `attacker attempts that reached verification`.\n\n| Stream | class | " . implode(' | ', $variants) . " |\n|---|---|" . str_repeat('---|', count($variants)) . "\n";
foreach (wsbPrimaryOrder() as $st) {
    $cells = [];
    foreach ($variants as $v) {
        $r = $get($v, $st, 'login', 'pre+out');
        $cells[] = $r === null ? '-' : "L {$r['legitDenied']}/{$r['legitAttempts']} (c{$r['legitDeniedByCorrelation']}) · A {$r['attackDenied']}/{$r['attackAttempts']} · reach {$r['attackReachedVerification']}";
    }
    $md .= "| $st | " . wsbClass($st) . ' | ' . implode(' | ', $cells) . " |\n";
}
foreach ([['login', 'out', 'Record-only Host (recordFailure/recordSuccess only)'], ['login', 'pre', 'Check-only Host (checkOnly only)'], ['api', 'out', 'API_OVERUSE Host (recordSuccess only, no account)'], ['otp', 'pre+out', 'OTP policy, pre+out']] as [$shape, $fl, $title]) {
    $md .= "\n## $title (shape=$shape, flow=$fl)\n\n| Stream | class | " . implode(' | ', $variants) . " |\n|---|---|" . str_repeat('---|', count($variants)) . "\n";
    foreach (wsbPrimaryOrder() as $st) {
        $cells = [];
        $any = false;
        foreach ($variants as $v) {
            $r = $get($v, $st, $shape, $fl);
            $any = $any || $r !== null;
            $cells[] = $r === null ? '-' : "L {$r['legitDenied']}/{$r['legitAttempts']} · A {$r['attackDenied']}/{$r['attackAttempts']} · reach {$r['attackReachedVerification']}";
        }
        if ($any) {
            $md .= "| $st | " . wsbClass($st) . ' | ' . implode(' | ', $cells) . " |\n";
        }
    }
}
// ---- generated evidence tables for A02..A05 ----------------------------------------------------------------------------------------
$pick = static function (string $v, string $sc, string $stream): array {
    global $A;
    return array_values(array_filter($A[$v][$sc] ?? [], static fn(array $r): bool => $r['s'] === $stream));
};
$md .= "\n## Exact boundaries and recovery (controlled clock; A02)\n\n| Probe | " . implode(' | ', $variants) . " |\n|---|" . str_repeat('---|', count($variants)) . "\n";
$row = static function (string $label, callable $f) use ($variants): string {
    return '| ' . $label . ' | ' . implode(' | ', array_map($f, $variants)) . " |\n";
};
$md .= $row('B1 lone legitimate client after a 3-member trigger: first allowed second', static fn(string $v): string => (string) ($pick($v, 'a02_boundaries', 'A02.B1.lone-legit-after-trigger')[0]['legitFirstAllowedT'] ?? '-'));
$md .= $row('B5 one seed burst then silence: legit denials / last denial second', static function (string $v) use ($pick): string {
    $r = $pick($v, 'a02_boundaries', 'A02.B5.single-seed-then-silence')[0] ?? null;
    return $r === null ? '-' : $r['legitDenied'] . '/' . $r['legitAttempts'] . ' · last ' . ($r['lastLegitDenialT'] ?? 'none');
});
$md .= $row('B2 third member at 599 s, failing / succeeding (record-only): blocked?', static function (string $v) use ($pick): string {
    $o = [];
    foreach ($pick($v, 'a02_boundaries', 'A02.B2.set-ttl-boundary') as $r) {
        if ($r['flow'] === 'out' && $r['f3At'] === 599) {
            $o[] = $r['f3Outcome'] . '=' . ($r['f3Blocked'] ? 'B' : 'a');
        }
    }
    return implode(' ', $o);
});
$md .= $row('B2 third member at 600 s (set expired), failing / succeeding: blocked?', static function (string $v) use ($pick): string {
    $o = [];
    foreach ($pick($v, 'a02_boundaries', 'A02.B2.set-ttl-boundary') as $r) {
        if ($r['flow'] === 'out' && $r['f3At'] === 600) {
            $o[] = $r['f3Outcome'] . '=' . ($r['f3Blocked'] ? 'B' : 'a');
        }
    }
    return implode(' ', $o);
});
$md .= $row('B3 WATCH: second pair ends at 1800 s / 1801 s (record-only): blocked?', static function (string $v) use ($pick): string {
    $o = [];
    foreach ($pick($v, 'a02_boundaries', 'A02.B3.watch-boundary') as $r) {
        if ($r['flow'] === 'out') {
            $o[] = $r['secondPairEndsAt'] . '=' . ($r['blockedAtSecondPairEnd'] ? 'B' : 'a');
        }
    }
    return implode(' ', $o);
});
$md .= $row('B4 persistent attacker (1 req/s): runs / block issuances', static function (string $v) use ($pick): string {
    $r = $pick($v, 'a02_boundaries', 'A02.B4.persistent-attacker-reissue')[0] ?? null;
    return $r === null ? '-' : $r['attackerRuns'] . ' · ' . $r['blockIssuances'];
});

$md .= "\n## Rotation, ephemeral overflow, backend failure (A03)\n\n| Probe | " . implode(' | ', $variants) . " |\n|---|" . str_repeat('---|', count($variants)) . "\n";
$md .= $row('R1 pair split across an outer-key rotation: third member blocked?', static function (string $v) use ($pick): string {
    foreach ($pick($v, 'a03_rotation_ephemeral_backend', 'A03.R1.rotation') as $r) {
        if (str_starts_with($r['shape'], 'rotated')) {
            return $r['thirdBlocked'] ? 'yes' : '**NO**';
        }
    }
    return '-';
});
$md .= $row('R2 block issued before rotation still enforced after', static fn(string $v): string => ($pick($v, 'a03_rotation_ephemeral_backend', 'A03.R2.block-continuity')[0]['blockedAfterRotation'] ?? null) ? 'yes' : 'NO');
$md .= $row('F1a/F1b backend failure propagates (churn-set write / witness write)', static function (string $v) use ($pick): string {
    $a = $pick($v, 'a03_rotation_ephemeral_backend', 'A03.F1a.churn-set-write')[0] ?? null;
    $b = $pick($v, 'a03_rotation_ephemeral_backend', 'A03.F1b.witness-write')[0] ?? null;

    return ($a['result'] ?? '-') . ' / ' . ($b === null || ! $b['applicable'] ? 'n/a' : $b['result']);
});

$md .= "\n## Storage and state growth (A04 in-memory, A05 real Redis)\n\n| Probe | " . implode(' | ', $variants) . " |\n|---|" . str_repeat('---|', count($variants)) . "\n";
$md .= $row('A04 S3: 3000 unique anchors, correlation ON, nobody denied: entries (sets+flags) per request', static fn(string $v): string => (string) ($pick($v, 'a04_state_bounds', 'A04.S3')[0]['entriesPerRequest'] ?? '-'));
$md .= $row('A04 S3: bounded-set classes (ttl/cap: calls)', static function (string $v) use ($pick): string {
    $c = $pick($v, 'a04_state_bounds', 'A04.S3')[0]['classes'] ?? [];
    return implode(' ', array_map(static fn(string $k, array $x): string => "$k:{$x['calls']}", array_keys($c), $c));
});
$md .= $row('A04 after 2000 s idle: entries left', static function (string $v) use ($pick): string {
    $i = $pick($v, 'a04_state_bounds', 'A04.S3')[0]['afterIdle2000s'] ?? null;
    return $i === null ? '-' : (string) ($i['sets'] + $i['flags']);
});
$md .= $row('A05 R3 real Redis: keys created per request, rounded (400 rotating anchors)', static fn(string $v): string => (string) ($pick($v, 'a05_redis', 'A05.R3.anchor-rotation')[0]['keysPerRequestRounded'] ?? '-'));
$md .= $row('A05 R4 real Redis: 8 concurrent failures of ONE fingerprint (20 rounds) vs the distinct-evidence tally (correct = 1)', static function (string $v) use ($pick): string {
    $r = $pick($v, 'a05_redis', 'A05.R4.duplicate-member-race')[0] ?? null;
    return $r === null || isset($r['applicable']) ? 'n/a' : ($r['inflationObserved'] ? 'inflated' : 'exact') . ' · tally reached the evidence threshold (3) with ONE fingerprint: ' . ($r['oneFingerprintReachedThreshold'] ? 'yes' : 'no');
});

$md .= "\n## User-visible legitimate denials under paid poisoning, all causes (C2)\n\n";
foreach ($gate as $g) {
    if (str_starts_with($g['gate'], 'C2') && in_array($g['variant'], $candidates, true)) {
        $md .= "**{$g['variant']}**\n\n| Stream/flow | S0 | {$g['variant']} | with correlation disabled |\n|---|---|---|---|\n";
        foreach ($g['detail'] as $k => $x) {
            $md .= "| $k | {$x['S0']} | {$x[$g['variant']]} | {$x['controlNoCorrelation']} |\n";
        }
        $md .= "\n";
    }
}

$md .= "\n## Offline prediction (PR #103 model) vs experimental runtime — login pre+out\n\nThe model has no K4/K1-spray/score protections and (for A1/PW/P2) assumes the M2 distinct-arrival WATCH, which needs a store-contract signal; the runtime candidates do not. Differences are therefore expected and are reported, not hidden. A1 here is the runtime control \"LOW exemption only\" (no M2).\n\n";
foreach ($fidelity as $p => $rowsF) {
    $md .= "**$p**: " . count(array_filter($rowsF, static fn(array $x): bool => $x['same'])) . '/' . count($rowsF) . " identical.\n\n| Stream | model | runtime | same |\n|---|---|---|---|\n";
    foreach ($rowsF as $id => $x) {
        $md .= "| $id | {$x['model']} | {$x['runtime']} | " . ($x['same'] ? 'yes' : '**NO**') . " |\n";
    }
    $md .= "\n";
}
file_put_contents("$out/summary.md", rtrim($md) . "\n");
echo "gates: " . count($gate) . " rows -> $out/gates.jsonl\n";
