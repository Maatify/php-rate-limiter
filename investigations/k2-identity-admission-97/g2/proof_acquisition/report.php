<?php

declare(strict_types=1);

// G2 gate evaluation (PLAN.md §5–§6) over the scenario outputs. Writes summary.md and gates.json into <out-dir>.
//   php report.php <in-dir> <out-dir>
// Exit 0: every mandatory gate passes. Exit 3: the harness is valid (every detector fired, every fixture is valid) and at least
// one mandatory gate fails. Exit 1: the harness is not valid (a detector did not fire or a fixture-validity check failed).

[$in, $out] = [$argv[1] ?? '', $argv[2] ?? ''];
/** @return list<array<string, mixed>> */
$load = static function (string $f) use ($in): array {
    $rows = [];
    foreach (file("$in/$f", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $rows[] = (array) json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $rows;
};
$ab = $load('ab.jsonl');
$c = $load('c.jsonl');
$d = $load('d.jsonl');
$e = $load('e.jsonl');
$f = $load('f.jsonl');
$g = $load('g.jsonl');

$sel = static fn(array $rows, array $where): array => array_values(array_filter($rows, static function (array $r) use ($where): bool {
    foreach ($where as $k => $v) {
        if (($r[$k] ?? null) !== $v) {
            return false;
        }
    }

    return true;
}));
$admittedCount = static fn(array $rows): int => count(array_filter($rows, static fn(array $r): bool => $r['owner']['admitted']));
$gates = [];
$invalid = [];
$set = static function (string $gate, string $case, bool $ok, string $detail) use (&$gates): void {
    $gates[$gate][$case] = ['result' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

// ---- fixture validity and detectors (harness validity) ----
$noproof = $sel($ab, ['fixture' => 'ACQ', 'arm' => 'NOPROOF']);
$refusedNoproof = count($noproof) - $admittedCount($noproof);
$validity = ['ACQ NOPROOF refuses the owner in >= 10/12' => $refusedNoproof >= 10];
$detectors = [];
foreach (['N-A-ACCTCAP', 'N-A-SINGLE', 'N-U', 'N-B-BOUND', 'N-B-SHORT', 'N-B-TTL', 'N-B-SHAREDOP'] as $arm) {
    $rows = $sel($ab, ['fixture' => 'ACQ', 'arm' => $arm]);
    $detectors[$arm] = count($rows) - $admittedCount($rows);
}
foreach (['N-BIND-A', 'N-BIND-B'] as $arm) {
    $detectors[$arm] = count($sel($c, ['arm' => $arm])[0]['accepted']);
}
$mut = $sel($f, ['test' => 'race', 'mutant' => true])[0];
$detectors['F mutant nonce'] = $mut['nonceOverAdmittedRounds'];
$detectors['F mutant redeem'] = $mut['redeemOverAdmittedRounds'];
$detectors['F mutant issuance'] = $mut['issueOverCapRounds'];
foreach ($detectors as $name => $n) {
    if ($n < 1) {
        $invalid[] = "detector $name did not fire";
    }
}
foreach ($validity as $name => $ok) {
    if (! $ok) {
        $invalid[] = "fixture validity: $name";
    }
}

// ---- A, B ----
$checkAcq = static function (array $rows, array $control): array {
    $bad = [];
    foreach ($rows as $i => $r) {
        $o = $r['owner'];
        if (! $o['admitted'] || ($o['lane'] !== 'U' && ! $o['acquired'])) {
            $bad[] = "A-1 arrival {$r['arrival']}: " . implode(',', $o['refusals']);
        }
        if ($o['proofPathRefusals'] > 0) {
            $bad[] = "A-2 arrival {$r['arrival']}: proof-path refusals " . implode(',', $o['refusals']);
        }
        if ($control !== [] && $r['uAdmissionsTotal'] !== $control[$i]['uAdmissionsTotal']) {
            $bad[] = "A-2 arrival {$r['arrival']}: U admissions {$r['uAdmissionsTotal']} vs NOPROOF {$control[$i]['uAdmissionsTotal']}";
        }
    }

    return $bad;
};
foreach (['PA', 'PB'] as $arm) {
    $rows = $sel($ab, ['fixture' => 'ACQ', 'arm' => $arm]);
    $bad = $checkAcq($rows, $noproof);
    $set('A-1/A-2', $arm, $bad === [], $bad === [] ? sprintf('%d/12 admitted, 0 proof-path refusals, U admissions = NOPROOF', $admittedCount($rows)) : implode('; ', $bad));
    $a3 = [];
    foreach ($rows as $i => $r) {
        if ($r['attacker']['reachForeignMaxWindow'] > 4 || $r['attacker']['reachForeign'] !== $noproof[$i]['attacker']['reachForeign']
            || $r['attacker']['acquisitions'] !== 0 || $r['attacker']['pAdmissions'] !== 0 || $r['capsExceeded'] !== []) {
            $a3[] = "arrival {$r['arrival']}";
        }
    }
    $set('A-3', $arm, $a3 === [], $a3 === [] ? sprintf('attacker reach_foreign %d per run (max %d per window) = NOPROOF; 0 acquisitions; 0 P admissions', $rows[0]['attacker']['reachForeign'], $rows[0]['attacker']['reachForeignMaxWindow']) : implode('; ', $a3));
    $b1 = [];
    foreach (['ACQ-x10', 'ACQ-LONG'] as $fx) {
        $ctl = $sel($ab, ['fixture' => $fx, 'arm' => 'NOPROOF']);
        foreach ($checkAcq($sel($ab, ['fixture' => $fx, 'arm' => $arm]), $ctl) as $x) {
            $b1[] = "$fx $x";
        }
    }
    $set('B-1', $arm, $b1 === [], $b1 === [] ? 'A-1 and A-2 hold in ACQ-x10 and ACQ-LONG' : implode('; ', $b1));
}

// ---- C ----
foreach (['PA', 'PB'] as $arm) {
    $r = $sel($c, ['arm' => $arm])[0];
    $set('C', $arm, $r['accepted'] === [], sprintf('%d probes, %d accepted%s', $r['probeCount'], count($r['accepted']), $r['accepted'] === [] ? '' : ': ' . implode(',', $r['accepted'])));
}

// ---- D (admission layer and proof checks are proof-kind independent; applied to both cases) ----
$dBad = [];
foreach (['D-cid', 'D-ip', 'D-dev'] as $fx) {
    $rs = $sel($d, ['fixture' => $fx]);
    if ($rs[0]['reachForeign'] !== $rs[1]['reachForeign'] || max($rs[0]['reachForeignMaxWindow'], $rs[1]['reachForeignMaxWindow']) > 4 || isset($rs[1]['lanes']['P'])) {
        $dBad[] = $fx;
    }
}
foreach ($sel($d, ['fixture' => 'D-stolen']) as $r) {
    $cap = ['one' => 6, 'renew' => 10, 'renew-dev' => 10][$r['mode']];
    if ($r['reachProofMaxWindow'] > $cap || $r['reachProofMaxDay'] > 60 || $r['admittedDevices'] > 10) {
        $dBad[] = "D-stolen {$r['mode']} {$r['T']}";
    }
}
$own = $sel($d, ['fixture' => 'D-own']);
if ($own[0]['reachForeign'] !== $own[1]['reachForeign'] || max($own[0]['reachOwnMaxPerAccountWindow'], $own[1]['reachOwnMaxPerAccountWindow']) > 10) {
    $dBad[] = 'D-own';
}
$acqNoKey = 0;
foreach ($ab as $r) {
    if (in_array($r['arm'], ['PA', 'PB'], true)) {
        $acqNoKey += $r['attacker']['acquisitions'];
    }
}
if ($acqNoKey !== 0) {
    $dBad[] = "attacker acquisitions without the key: $acqNoKey";
}
foreach (['PA', 'PB'] as $arm) {
    $set('D', $arm, $dBad === [], $dBad === [] ? 'reach_foreign 1 vs N identical, <= U_acct per window; stolen key <= P_proof / P_acct / P_acct_day / N_P; own accounts <= P_acct each; 0 acquisitions without the key' : implode('; ', $dBad));
}

// ---- E ----
foreach (['PA', 'PB'] as $arm) {
    $bad = [];
    foreach ($sel($e, ['arm' => $arm, 'diagnostic' => false]) as $r) {
        $sub = [];
        if (! $r['pCountersIdentical']) {
            $sub[] = '(i) P counters ' . json_encode($r['pCountersRun']) . ' vs control ' . json_encode($r['pCountersControl']);
        }
        if ($r['populationOriginDenials'] !== 0) {
            $sub[] = "(ii) {$r['populationOriginDenials']} population-origin denials";
        }
        if (! $r['acquisitionIdentical']) {
            $sub[] = '(iii) acquisition differs';
        }
        if ($r['unattributed'] !== []) {
            $sub[] = '(iv) unattributed differences at owner requests ' . implode(',', $r['unattributed']);
        }
        if ($sub !== []) {
            $bad[] = $r['fixture'] . ': ' . implode('; ', $sub);
        }
    }
    $set('E', $arm, $bad === [], $bad === [] ? 'all fixtures pass (i)-(iv)' : implode(' | ', $bad));
}

// ---- F ----
$at = $sel($f, ['test' => 'race', 'mutant' => false])[0];
$lost = $sel($f, ['test' => 'lost-reply'])[0];
$mal = $sel($f, ['test' => 'malformed'])[0];
$down = $sel($f, ['test' => 'backend-unreachable'])[0];
$fOk = array_unique($at['nonceSuccessesPerRound']) === [1] && array_unique($at['redeemSuccessesPerRound']) === [1]
    && max($at['issuedPerRoundCap3']) <= 3 && $at['workerErrors'] === 0
    && $lost['nonceRetry'] === 'used' && $lost['redeemRetry'] === 'used' && $lost['issueCounter'] <= 3
    && count(array_filter($lost['issueRetries'], static fn(string $x): bool => $x === 'issued')) <= 3
    && count(array_filter($mal['cases'], static fn(array $x): bool => $x['result'] !== 'error' || ! $x['unchanged'])) === 0
    && $down['tokenMinted'] === false && $down['lane'] === 'U' && $down['admissions'] === 0;
foreach (['PA', 'PB'] as $arm) {
    $set('F', $arm, $fOk, sprintf(
        '%d workers x %d rounds: nonce and link exactly 1 success per round, issuance %s per round at cap 3; lost-reply retries %s / %s, issuance counter %d; malformed: error, no write; outage: no proof, lane U, 0 admissions',
        $at['workers'],
        $at['rounds'],
        implode('/', array_unique($at['issuedPerRoundCap3'])),
        $lost['nonceRetry'],
        $lost['redeemRetry'],
        $lost['issueCounter'],
    ));
}

// ---- G ----
$gOk = true;
foreach (['pre', 'pre+out'] as $flow) {
    $r = $sel($g, ['arm' => 'PA', 'flow' => $flow])[0];
    for ($i = 0, $n = strlen($r['ownerLanes']); $i < $n; $i++) {
        $gOk = $gOk && ($r['ownerLanes'][$i] !== 'P' || $r['ownerDecisions'][$i] === 'A');
    }
    $gOk = $gOk && $r['attackerReachMaxWindow'] <= 4 && $r['ownerProofAcquired'];
}
$np = $sel($g, ['arm' => 'NOPROOF'])[0];
$gOk = $gOk && (str_contains($np['ownerDecisions'], 'H') || str_contains($np['ownerDecisions'], 'S'));
foreach (['PA', 'PB'] as $arm) {
    $set('G', $arm, $gOk, 'pre and pre+out: owner P requests admitted, attacker <= U_acct per window; NOPROOF owner refused; out reported only');
}

// ---- verdict (PLAN.md §6) ----
$verdict = [];
foreach (['PA' => 'A', 'PB' => 'B'] as $arm => $case) {
    $failed = [];
    foreach ($gates as $gate => $byArm) {
        if (($byArm[$arm]['result'] ?? 'PASS') === 'FAIL') {
            $failed[] = $gate;
        }
    }
    $verdict[$case] = $invalid !== [] ? 'NOT EVIDENCED (harness invalid)'
        : ($failed === [] ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT (failed: ' . implode(', ', $failed) . ')');
}
$verdict['C'] = 'NO-GO (Gate 1; not tested)';

// ---- residuals and measured-only items (B-2, G out) ----
$resid = [];
foreach (['COLOC', 'GLOB'] as $fx) {
    foreach (['PA', 'PB'] as $arm) {
        $rows = $sel($ab, ['fixture' => $fx, 'arm' => $arm]);
        $firstSrcRefused = count(array_filter($rows, static fn(array $r): bool => $r['owner']['proofPathRefusals'] > 0));
        $resid[] = ['fixture' => $fx, 'arm' => $arm, 'ownerAdmitted' => $admittedCount($rows), 'arrivals' => count($rows), 'arrivalsWithProofPathRefusal' => $firstSrcRefused];
    }
}
$long = $sel($ab, ['fixture' => 'ACQ-LONG', 'arm' => 'PB'])[0];
$inval = $sel($ab, ['fixture' => 'ACQ', 'arm' => 'N-B-INVAL']);
$gOut = $sel($g, ['arm' => 'PA', 'flow' => 'out'])[0];

$gatesJson = ['verdict' => $verdict, 'harnessInvalid' => $invalid, 'fixtureValidity' => $validity, 'detectors' => $detectors, 'gates' => $gates,
    'residuals' => ['B-2' => $resid, 'messagesToVictimPerDayPB' => intdiv($long['messagesToVictim'] * 86400, 14400),
        'messagesBoundPerDay' => ['perOp' => 3 * intdiv(86400, 900), 'linkableOps' => 2], 'N-B-INVAL ownerFailures' => count($inval) - $admittedCount($inval),
        'G out attackerReach' => $gOut['attackerReach'] . '/' . $gOut['attackAttempts']]];
file_put_contents("$out/gates.json", json_encode($gatesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// ---- summary.md ----
$md = "# G2 proof acquisition — generated summary\n\nGenerated by `report.php` from the scenario outputs. Do not edit by hand.\n\n## Verdict (PLAN.md §6)\n\n| Case | Verdict |\n| --- | --- |\n";
foreach ($verdict as $k => $v) {
    $md .= "| $k | $v |\n";
}
$md .= "\nHarness validity: " . ($invalid === [] ? 'every detector fired and every fixture-validity check passed' : implode('; ', $invalid)) . ".\n\n## Gates\n\n| Gate | Arm | Result | Detail |\n| --- | --- | --- | --- |\n";
foreach ($gates as $gate => $byArm) {
    foreach ($byArm as $arm => $x) {
        $md .= "| $gate | $arm | {$x['result']} | " . str_replace('|', '\\|', $x['detail']) . " |\n";
    }
}
$md .= "\n## Detectors (each must be >= 1)\n\n| Detector | Fired |\n| --- | --- |\n";
foreach ($detectors as $k => $v) {
    $md .= "| $k | $v |\n";
}
$md .= "\n## Section A/B owner outcomes per arm (12 arrivals; U = admitted in U, P = admitted in P after acquisition, x = not admitted)\n\n| Fixture | Arm | Owner per arrival | Owner requests (median) | Seconds to admission (max) | Attacker reach_foreign | Victim messages |\n| --- | --- | --- | --- | --- | --- | --- |\n";
$groups = [];
foreach ($ab as $r) {
    $groups[$r['fixture'] . '|' . $r['arm']][] = $r;
}
foreach ($groups as $k => $rows) {
    [$fx, $arm] = explode('|', $k);
    $s = implode('', array_map(static fn(array $r): string => $r['owner']['admitted'] ? $r['owner']['lane'] : 'x', $rows));
    $req = array_map(static fn(array $r): int => $r['owner']['requests'], $rows);
    sort($req);
    $sec = array_filter(array_map(static fn(array $r): ?int => $r['owner']['seconds'], $rows), static fn(?int $x): bool => $x !== null);
    $md .= sprintf(
        "| %s | %s | `%s` | %d | %s | %d | %d |\n",
        $fx,
        $arm,
        $s,
        $req[intdiv(count($req), 2)],
        $sec === [] ? '-' : (string) max($sec),
        $rows[0]['attacker']['reachForeign'],
        $rows[0]['messagesToVictim'],
    );
}
$md .= "\n## Section C probes\n\n| Arm | Probes | Accepted |\n| --- | --- | --- |\n";
foreach ($c as $r) {
    $md .= "| {$r['arm']} | {$r['probeCount']} | " . ($r['accepted'] === [] ? '0' : count($r['accepted']) . ': ' . implode(', ', $r['accepted'])) . " |\n";
}
$md .= "\n## Section D\n\n| Fixture | Variant | Result |\n| --- | --- | --- |\n";
foreach ($d as $r) {
    $fx = $r['fixture'];
    $variant = isset($r['n']) ? "n = {$r['n']}" : (isset($r['m']) ? "m = {$r['m']}" : "{$r['mode']}, {$r['T']} s");
    unset($r['s'], $r['fixture'], $r['n'], $r['m'], $r['mode'], $r['T']);
    $md .= "| $fx | $variant | `" . json_encode($r, JSON_UNESCAPED_SLASHES) . "` |\n";
}
$md .= "\n## Section E (real candidate pipeline, DAOF) — owner decisions, run vs no-population control\n\n| Arm | Fixture | Owner run | Owner control | Lanes | Identical | (i) P counters identical | (ii) pop-origin | (iii) acquisition identical | (iv) unattributed | U→P state writes (c9) |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($e as $r) {
    $md .= sprintf(
        "| %s | %s | `%s` | `%s` | `%s` | %s | %s | %d | %s | %d | %d/%d |\n",
        $r['arm'],
        $r['fixture'],
        $r['run']['dec'],
        $r['control']['dec'],
        $r['run']['lanes'],
        $r['identical'] ? 'yes' : 'no',
        $r['pCountersIdentical'] ? 'yes' : 'no (' . json_encode($r['pCountersRun']) . ' vs ' . json_encode($r['pCountersControl']) . ')',
        $r['populationOriginDenials'],
        $r['acquisitionIdentical'] ? 'yes' : 'no',
        count($r['unattributed']),
        $r['c9Violations'],
        $r['c9Checked'],
    );
}
$md .= "\nRows marked post-hoc diagnostic are not pre-registered and are excluded from every gate and from the verdict.\n\nDifferences and their trace origins:\n\n";
foreach ($e as $r) {
    foreach ($r['differences'] as $x) {
        $md .= "* {$r['arm']} {$r['fixture']}, owner request {$x['k']} ({$x['lane']}): run `{$x['run']}`, control `{$x['control']}`" . ($x['undeclared'] === [] ? '' : ', undeclared ' . implode(',', $x['undeclared'])) . "\n";
    }
}
$md .= "\n## Section F (real Redis)\n\n";
foreach ($f as $r) {
    unset($r['s']);
    $md .= '* `' . json_encode($r, JSON_UNESCAPED_SLASHES) . "`\n";
}
$md .= "\n## Section G (host modes, real candidate pipeline)\n\n| Arm | Flow | Attacker reach / attempts | Max per window | Owner decisions | Owner lanes |\n| --- | --- | --- | --- | --- | --- |\n";
foreach ($g as $r) {
    $md .= "| {$r['arm']} | {$r['flow']} | {$r['attackerReach']}/{$r['attackAttempts']} | {$r['attackerReachMaxWindow']} | `{$r['ownerDecisions']}` | `{$r['ownerLanes']}` |\n";
}
$md .= "\n## Residuals (B-2, measured, not judged)\n\n| Fixture | Arm | Owner admitted | Arrivals with a proof-path refusal |\n| --- | --- | --- | --- |\n";
foreach ($resid as $x) {
    $md .= "| {$x['fixture']} | {$x['arm']} | {$x['ownerAdmitted']}/{$x['arrivals']} | {$x['arrivalsWithProofPathRefusal']} |\n";
}
$md .= sprintf("\n* PB, ACQ-LONG: %d attacker-triggered messages reached the victim's mailbox per day (bound `ISS_acct · 86400 / W_iss` = %d per linkable operation, 2 operations).\n", $gatesJson['residuals']['messagesToVictimPerDayPB'], 3 * intdiv(86400, 900));
$md .= sprintf("* N-B-INVAL (measured only): %d of %d owner arrivals not admitted.\n", count($inval) - $admittedCount($inval), count($inval));
$md .= sprintf("* G, flow `out`: attacker reach %s (no pre-verifier bound, by call order).\n", $gatesJson['residuals']['G out attackerReach']);
file_put_contents("$out/summary.md", $md);

echo $md;
exit($invalid !== [] ? 1 : (str_contains(json_encode($verdict, JSON_THROW_ON_ERROR), 'NO-GO AFTER') ? 3 : 0));
