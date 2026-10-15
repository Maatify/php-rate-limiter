<?php

declare(strict_types=1);

// Validates the raw evidence and generates expected/summary.md + expected/observations.jsonl.
//
//   php report.php [h01.jsonl] [h02.jsonl] [outDir]
//
// Exit 5  EVIDENCE INVALID (fail-closed): missing / empty / malformed / mislabelled file, wrong provenance or runtime hash, unknown /
//         duplicate / missing cell or row, a field outside the exact per-class schema, a character outside the decision alphabet, a
//         record step that is not allowed after its check step, or ANY stored metric that differs from the value re-derived from the
//         archived per-request traces and the deterministic schedule.
// Exit 6  evidence valid, but at least one descriptive observation is NOT_REPRODUCED.
//
// Observations are facts about the unpatched MAIN runtime (H01) and about the H02 bootstrap model whose ESTABLISHED part is IDEALIZED.
// A REPRODUCED observation is never a security acceptance of any direction.
require_once __DIR__ . '/lib/hmci.php';

$h01Path = $argv[1] ?? __DIR__ . '/expected/h01_input_modes.jsonl';
$h02Path = $argv[2] ?? __DIR__ . '/expected/h02_bootstrap.jsonl';
$outDir = $argv[3] ?? __DIR__ . '/expected';

function invalid(string $why): never
{
    fwrite(STDERR, "report: EVIDENCE INVALID — $why\n");
    exit(5);
}

/** @return list<array<string, mixed>> */
function readJsonl(string $path, string $scenario): array
{
    if (! is_file($path) || filesize($path) === 0) {
        invalid("missing or empty $path");
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $n => $line) {
        try {
            $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            invalid("$scenario: malformed JSON at line " . ($n + 1));
        }
        if (! is_array($r) || ($r['s'] ?? null) !== $scenario) {
            invalid("$scenario: foreign scenario label at line " . ($n + 1));
        }
        $rows[] = $r;
    }

    return $rows;
}

/** @param array<string, mixed> $r */
function exactKeys(array $r, array $keys, string $where): void
{
    $have = array_keys($r);
    sort($have);
    sort($keys);
    if ($have !== $keys) {
        invalid("$where: fields [" . implode(',', $have) . '] differ from the schema [' . implode(',', $keys) . ']');
    }
}

/** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> non-provenance rows */
function checkProvenance(array $rows, string $scenario): array
{
    $prov = array_values(array_filter($rows, static fn(array $r): bool => ($r['row'] ?? null) === 'provenance'));
    if (count($prov) !== 1) {
        invalid("$scenario: expected exactly one provenance row, found " . count($prov));
    }
    $p = $prov[0];
    exactKeys($p, ['s', 'row', 'level', 'baseSha', 'sha256', 'patched'], "$scenario provenance");
    if ($p['level'] !== 'S0_ON_MAIN_RUNTIME_OBSERVED' || $p['baseSha'] !== HMCI_BASELINE_SHA || $p['patched'] !== false) {
        invalid("$scenario: wrong provenance label");
    }
    $want = [];
    foreach (HMCI_RUNTIME_SHA256 as $class => $sha) {
        $want[substr($class, strrpos($class, '\\') + 1)] = $sha;
    }
    if (! is_array($p['sha256']) || $p['sha256'] !== $want) {
        invalid("$scenario: runtime hash mismatch");
    }
    if ($rows[0] !== $p) {
        invalid("$scenario: provenance must be the first row");
    }

    return array_slice($rows, 1);
}

function assertAlphabet(string $s, string $pattern, string $where): void
{
    if (preg_match($pattern, $s) !== 1) {
        invalid("$where: characters outside the decision alphabet");
    }
}

// ---------------------------------------------------------------------------------------------------------------------------- H01
$h01 = [];
foreach (checkProvenance(readJsonl($h01Path, 'h01'), 'h01') as $i => $r) {
    $where = 'h01 row ' . ($i + 2);
    if (($r['row'] ?? null) !== 'cell') {
        invalid("$where: unknown row type");
    }
    $stream = $r['stream'] ?? null;
    $mode = $r['mode'] ?? null;
    if (! is_string($stream) || ! isset(HMCI_H01_STREAMS[$stream]) || ! in_array($mode, HMCI_MODES, true)) {
        invalid("$where: unknown stream / mode");
    }
    if (isset($h01[$stream][$mode])) {
        invalid("$where: duplicate cell $stream/$mode");
    }
    [$class, $shape] = HMCI_H01_STREAMS[$stream];
    $common = ['s', 'row', 'stream', 'class', 'mode', 'attempts', 'check', 'record'];
    exactKeys($r, match ($class) {
        'benign' => [...$common, 'conf', 'denied', 'users', 'usersDenied', 'recordBlocked', 'firstDenialBlockedKeys'],
        'attack' => [...$common, 'conf', 'reach'],
        'owner' => [...$common, 'reach', 'distinctK5'],
    }, $where);
    if ($r['class'] !== $class) {
        invalid("$where: class label does not match the stream");
    }
    $check = $r['check'];
    $record = $r['record'];
    if (! is_string($check) || ! is_string($record)) {
        invalid("$where: traces must be strings");
    }
    assertAlphabet($check, '/^[ASH]*$/', "$where check");
    assertAlphabet($record, '/^[ASH-]*$/', "$where record");
    $events = $class === 'benign' ? officeEvents($shape[0], $shape[1], 110) : null;
    $attempts = $events === null ? $shape : count($events);
    if ($r['attempts'] !== $attempts || strlen($check) !== $attempts || strlen($record) !== $attempts) {
        invalid("$where: attempt count differs from the deterministic schedule ($attempts)");
    }
    for ($k = 0; $k < $attempts; $k++) {
        // `pre+out`: an outcome is recorded if and only if checkOnly allowed.
        if (($check[$k] === 'A') !== ($record[$k] !== '-')) {
            invalid("$where: request " . ($k + 1) . " has record '{$record[$k]}' after check '{$check[$k]}'");
        }
    }
    $allowed = substr_count($check, 'A');
    if ($class !== 'owner' && $r['conf'] !== HMCI_MODE_CONFIDENCE[$mode]) {
        invalid("$where: confidence {$r['conf']} is not what mode $mode resolves to");
    }
    if ($class === 'benign') {
        $denied = [];
        foreach ($events as $k => $e) {
            if ($check[$k] !== 'A') {
                $denied[$e['who']] = true;
            }
        }
        $blocks = $r['firstDenialBlockedKeys'];
        if (! is_array($blocks) || array_filter(array_keys($blocks), static fn($k): bool => preg_match('/^(-|K[1-5](\+K[1-5])*)$/', (string) $k) !== 1) !== []) {
            invalid("$where: malformed attribution map");
        }
        if ($r['denied'] !== $attempts - $allowed
            || $r['users'] !== $shape[0]
            || $r['usersDenied'] !== count($denied)
            || $r['recordBlocked'] !== strlen(str_replace(['A', '-'], '', $record))
            || array_sum($blocks) !== count($denied)) {
            invalid("$where: a stored metric differs from the trace-derived value");
        }
    } else {
        if ($r['reach'] !== $allowed) {
            invalid("$where: reach {$r['reach']} differs from the trace-derived value $allowed");
        }
        if ($class === 'owner' && ! in_array($r['distinctK5'], [1, 2], true)) {
            invalid("$where: distinctK5 out of range");
        }
    }
    $h01[$stream][$mode] = $r;
}
foreach (array_keys(HMCI_H01_STREAMS) as $s) {
    foreach (HMCI_MODES as $m) {
        if (! isset($h01[$s][$m])) {
            invalid("h01: missing cell $s/$m");
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------------- H02
const H02_VARIANT_NAMES = ['MAIN', 'BASE', 'CAP5', 'CLR10', 'FIX7', 'ATK', 'ATKM'];
const H02_DAY_COUNT = 14;
$h02 = [];
foreach (checkProvenance(readJsonl($h02Path, 'h02'), 'h02') as $i => $r) {
    $where = 'h02 row ' . ($i + 2);
    if (($r['row'] ?? null) !== 'day') {
        invalid("$where: unknown row type");
    }
    exactKeys($r, ['s', 'row', 'variant', 'day', 'establishedAtStart', 'cleared', 'expired', 'newcomers', 'newcomersDeniedOnce',
        'newcomersAdmittedLogin', 'newcomersNoLoginToday', 'gained', 'cappedEligible', 'establishedAtEnd', 'attackerReach', 'check'], $where);
    $v = $r['variant'];
    $d = $r['day'];
    if (! in_array($v, H02_VARIANT_NAMES, true) || ! is_int($d) || $d < 1 || $d > H02_DAY_COUNT) {
        invalid("$where: unknown variant / day");
    }
    if (isset($h02[$v][$d])) {
        invalid("$where: duplicate row $v/day $d");
    }
    foreach (['establishedAtStart', 'cleared', 'expired', 'newcomers', 'newcomersDeniedOnce', 'newcomersAdmittedLogin',
        'newcomersNoLoginToday', 'gained', 'cappedEligible', 'establishedAtEnd'] as $f) {
        if (! is_int($r[$f]) || $r[$f] < 0) {
            invalid("$where: $f must be a non-negative integer");
        }
    }
    assertAlphabet((string) $r['check'], '/^[ASH]*$/', "$where check");
    $nonAllowed = strlen($r['check']) - substr_count($r['check'], 'A');
    $isAttack = in_array($v, ['ATK', 'ATKM'], true);
    if ($r['establishedAtStart'] + $r['newcomers'] !== 100
        || $r['newcomersAdmittedLogin'] + $r['newcomersNoLoginToday'] !== $r['newcomers']
        || $r['newcomersDeniedOnce'] > $r['newcomers']
        || $r['newcomersDeniedOnce'] > $nonAllowed
        || ($r['newcomers'] > 0 && $nonAllowed + substr_count($r['check'], 'A') < $r['newcomers'])
        || $r['establishedAtEnd'] !== $r['establishedAtStart'] + $r['gained']
        || $r['gained'] + $r['cappedEligible'] !== $r['newcomersAdmittedLogin']
        || (in_array($v, ['MAIN', 'ATKM'], true) && $r['gained'] !== 0)
        || ($v === 'CAP5' && $r['gained'] > 5)
        || ($v !== 'CLR10' && $r['cleared'] !== 0)
        || ($v !== 'FIX7' && $r['expired'] !== 0)
        || ($isAttack !== is_int($r['attackerReach']))
        || ($isAttack && ($r['attackerReach'] < 0 || $r['attackerReach'] > 71))) {
        invalid("$where: inconsistent day accounting");
    }
    $h02[$v][$d] = $r;
}
foreach (H02_VARIANT_NAMES as $v) {
    for ($d = 1; $d <= H02_DAY_COUNT; $d++) {
        if (! isset($h02[$v][$d])) {
            invalid("h02: missing row $v/day $d");
        }
        if ($d > 1) {
            $prev = $h02[$v][$d - 1];
            $cur = $h02[$v][$d];
            if ($cur['establishedAtStart'] !== $prev['establishedAtEnd'] - $cur['cleared'] - $cur['expired']) {
                invalid("h02: $v day $d does not continue day " . ($d - 1));
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------- observations
$v = static fn(string $s, string $m, string $k): mixed => $h01[$s][$m][$k];
$day = static fn(string $variant, int $d, string $k): mixed => $h02[$variant][$d][$k];
$series = static fn(string $variant, string $k): array => array_map(static fn(int $d): mixed => $day($variant, $d, $k), range(1, H02_DAY_COUNT));
$firstFullDay = static function (string $variant) use ($day): ?int {
    for ($d = 1; $d <= H02_DAY_COUNT; $d++) {
        if ($day($variant, $d, 'establishedAtEnd') === 100) {
            return $d;
        }
    }

    return null;
};
$rotationOk = static function () use ($v): bool {
    foreach (HMCI_MODES as $m) {
        foreach ([['SPR_R', 'SPR_S'], ['BRU_R', 'BRU_S']] as [$rot, $stable]) {
            // Rotating ids must not beat the SAME-mode stable id, nor sending no id at all (M0 of the stable stream).
            if ($v($rot, $m, 'reach') > $v($stable, $m, 'reach') || $v($rot, $m, 'reach') > $v($stable, 'M0', 'reach')) {
                return false;
            }
        }
    }

    return true;
};
$uaOk = static function () use ($v): bool {
    foreach (HMCI_MODES as $m) {
        if ($v('UAS', $m, 'distinctK5') !== 1 || $v('UAC', $m, 'distinctK5') !== 2 || $v('UAC', $m, 'reach') <= $v('UAS', $m, 'reach')) {
            return false;
        }
    }

    return true;
};
$atkMax = max($series('ATK', 'attackerReach'));
$atkmMax = max($series('ATKM', 'attackerReach'));
$atkmMin = min($series('ATKM', 'attackerReach'));
$mainNoLoginMin = min($series('MAIN', 'newcomersNoLoginToday'));
$baseFull = $firstFullDay('BASE');

$obs = [
    ['O1', 'H01', 'A NAT larger than the 50-member IP device cap is already mostly denied with no browser id (M0, K1 spray)',
        $v('OFF100', 'M0', 'usersDenied') >= 80, "OFF100 M0 usersDenied={$v('OFF100', 'M0', 'usersDenied')}/100"],
    ['O2', 'H01', 'In the measured streams, a per-browser id in clientFingerprint (current-runtime mapping of A) increases NAT collateral through K2 churn',
        $v('OFF100', 'M1', 'usersDenied') > $v('OFF100', 'M0', 'usersDenied') && $v('OFF12', 'M1', 'usersDenied') > $v('OFF12', 'M0', 'usersDenied'),
        "OFF12 M0→M1 {$v('OFF12', 'M0', 'usersDenied')}→{$v('OFF12', 'M1', 'usersDenied')}; OFF100 {$v('OFF100', 'M0', 'usersDenied')}→{$v('OFF100', 'M1', 'usersDenied')}"],
    ['O3', 'H01', 'sessionDeviceId without trust (M2) gives decisions identical to M1 in the measured streams (the id enters the composite DeviceFP)',
        $v('OFF100', 'M2', 'check') === $v('OFF100', 'M1', 'check') && $v('SPR_R', 'M2', 'check') === $v('SPR_R', 'M1', 'check'),
        'OFF100 and SPR_R check traces identical M1 vs M2'],
    ['O4', 'H01', 'Pre-authentication trust on a host cookie (M3) gives a stable-id sprayer full verifier reach (trusted K1 advisory)',
        $v('SPR_S', 'M3', 'reach') === 200 && $v('SPR_S', 'M0', 'reach') < 10, "SPR_S reach M0={$v('SPR_S', 'M0', 'reach')} M3={$v('SPR_S', 'M3', 'reach')}/200"],
    ['O5', 'H01', 'Pre-authentication trust (M3) does not relieve the measured office: K2 churn still denies trusted browsers',
        $v('OFF100', 'M3', 'usersDenied') >= $v('OFF100', 'M0', 'usersDenied'), "OFF100 M3 usersDenied={$v('OFF100', 'M3', 'usersDenied')}"],
    ['O6', 'H01', 'Pre-authentication trust (M3) widens per-account brute-force reach (known-device classification; DEC-021 bound applies)',
        $v('BRU_S', 'M3', 'reach') > $v('BRU_S', 'M0', 'reach'), "BRU_S reach M0={$v('BRU_S', 'M0', 'reach')} M3={$v('BRU_S', 'M3', 'reach')}/100"],
    ['O7', 'H01', 'correlationId = browser id (M4) removes credential-spray protection for a stable-id sprayer',
        $v('SPR_S', 'M4', 'reach') === 200, "SPR_S M4 reach={$v('SPR_S', 'M4', 'reach')}/200"],
    ['O8', 'H01', 'correlationId = browser id (M4) leaves the measured office unchanged (spray subjects stay distinct per browser)',
        $v('OFF100', 'M4', 'check') === $v('OFF100', 'M0', 'check'), 'OFF100 check traces identical M0 vs M4'],
    ['O9', 'H01', 'On MAIN, rotating the id per attempt never beats the same-mode stable id nor sending no id (M0)',
        $rotationOk(),
        'SPR_R ' . implode('/', array_map(static fn(string $m): int => $v('SPR_R', $m, 'reach'), HMCI_MODES))
        . ' vs SPR_S ' . implode('/', array_map(static fn(string $m): int => $v('SPR_S', $m, 'reach'), HMCI_MODES))
        . '; BRU_R ' . implode('/', array_map(static fn(string $m): int => $v('BRU_R', $m, 'reach'), HMCI_MODES))
        . ' vs BRU_S ' . implode('/', array_map(static fn(string $m): int => $v('BRU_S', $m, 'reach'), HMCI_MODES)) . ' (M0..M4)'],
    ['O10', 'H01', 'A browser update (UA major) splits a stable id into a new DeviceFP / K5 in every mode; the known owner gains reach',
        $uaOk(), "UAS reach={$v('UAS', 'M0', 'reach')} UAC reach={$v('UAC', 'M0', 'reach')} distinctK5 1→2 (all modes equal)"],
    ['B1', 'H02', 'On MAIN with retries, most of the office cannot log in at all on any day (the bootstrap entry point)',
        $mainNoLoginMin >= 80, 'MAIN newcomersNoLoginToday per day: ' . implode(',', $series('MAIN', 'newcomersNoLoginToday')) . ' of 100'],
    ['B2', 'H02', 'Even with IDEALIZED standing and no cap, the whole office is established only after several days',
        $baseFull !== null && $baseFull >= 5, 'BASE establishedAtEnd: ' . implode(',', $series('BASE', 'establishedAtEnd')) . " (100 % on day {$baseFull})"],
    ['B3', 'H02', 'A per-network cap of 5 per day leaves most of the office NEW for days and part of it after 14 days',
        $day('CAP5', H02_DAY_COUNT, 'establishedAtEnd') < 100, 'CAP5 establishedAtEnd: ' . implode(',', $series('CAP5', 'establishedAtEnd'))],
    ['B4', 'H02', 'Cookie clearing (10 %/day) or a fixed 7-day standing never reaches the whole office within 14 days',
        $day('CLR10', H02_DAY_COUNT, 'establishedAtEnd') < 100 && $day('FIX7', H02_DAY_COUNT, 'establishedAtEnd') < 100,
        'CLR10 end ' . implode(',', $series('CLR10', 'establishedAtEnd')) . '; FIX7 end ' . implode(',', $series('FIX7', 'establishedAtEnd'))],
    ['B5', 'H02', 'Removing established traffic from the shared pool RAISES a credential-less sprayer\'s reach above MAIN (G-T1 Δ = 0 is not met by construction)',
        $atkMax > $atkmMax, "attacker reach/day ATK " . implode(',', $series('ATK', 'attackerReach')) . " vs ATKM (MAIN) " . implode(',', $series('ATKM', 'attackerReach')) . " (ATKM range {$atkmMin}–{$atkmMax})"],
    ['B6', 'H02', 'An attacker on the NAT slows the office bootstrap',
        $day('ATK', 7, 'establishedAtEnd') < $day('BASE', 7, 'establishedAtEnd'),
        "day 7 established: ATK {$day('ATK', 7, 'establishedAtEnd')} vs BASE {$day('BASE', 7, 'establishedAtEnd')}"],
];

$jsonl = '';
foreach ($obs as [$id, $src, $title, $ok, $evidence]) {
    $jsonl .= json_encode(
        ['id' => $id, 'source' => $src, 'result' => $ok ? 'REPRODUCED' : 'NOT_REPRODUCED', 'title' => $title, 'evidence' => $evidence],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
}

$prov = readJsonl($h01Path, 'h01')[0];
$row = static fn(array $cells): string => '| ' . implode(' | ', $cells) . " |\n";
$md = "# HMCI evidence summary (generated by report.php — do not edit)\n\n";
$md .= "Runtime: `{$prov['level']}`, baseline `{$prov['baseSha']}`, unpatched. Hashes: ";
$md .= implode(', ', array_map(static fn(string $k, string $s): string => "`$k` `" . substr($s, 0, 8) . '…' . substr($s, -4) . '`', array_keys($prov['sha256']), $prov['sha256'])) . ".\n\n";
$md .= "## H01 — host-issued browser id through the existing inputs (REAL runtime, every cell)\n\n";
$md .= 'Modes: ' . implode('; ', array_map(static fn(string $m): string => "**$m** " . HMCI_MODE_TITLE[$m], HMCI_MODES)) . ".\n\n";
$md .= "### Benign shared NAT (Login, `pre+out`) — users denied at least once at `checkOnly` / denied checks / attempts\n\n";
$md .= $row(['Stream', ...HMCI_MODES]) . '|---|' . str_repeat('---|', count(HMCI_MODES)) . "\n";
foreach (['OFF12', 'OFF100'] as $s) {
    $md .= $row([$s, ...array_map(static fn(string $m): string => sprintf('%d/%d users · %d/%d checks', $v($s, $m, 'usersDenied'), $v($s, $m, 'users'), $v($s, $m, 'denied'), $v($s, $m, 'attempts')), HMCI_MODES)]);
}
$md .= "\nPersisted blocks active at each user's first denied check:\n\n" . $row(['Stream', ...HMCI_MODES]) . '|---|' . str_repeat('---|', count(HMCI_MODES)) . "\n";
foreach (['OFF12', 'OFF100'] as $s) {
    $md .= $row([$s, ...array_map(static fn(string $m): string => implode(', ', array_map(static fn(string $k, int $c): string => "{$k}×{$c}", array_keys($v($s, $m, 'firstDenialBlockedKeys')), $v($s, $m, 'firstDenialBlockedKeys'))), HMCI_MODES)]);
}
$md .= "\n### Attackers and the known owner — verifier reach (`checkOnly` allowed) / attempts\n\n" . $row(['Stream', ...HMCI_MODES]) . '|---|' . str_repeat('---|', count(HMCI_MODES)) . "\n";
foreach (['SPR_S', 'SPR_R', 'BRU_S', 'BRU_R', 'UAS', 'UAC'] as $s) {
    $md .= $row([$s, ...array_map(static fn(string $m): string => $v($s, $m, 'reach') . '/' . $v($s, $m, 'attempts'), HMCI_MODES)]);
}
$md .= "\nPer-window verifier reach of one unknown-device account attacker on MAIN (derived from the BRU_S M0 trace, one attempt / 30 s): ";
$bru = $v('BRU_S', 'M0', 'check');
$md .= implode(', ', array_map(static fn(int $w): string => sprintf('%d–%d s: %d', $w * 600, ($w + 1) * 600, substr_count(substr($bru, $w * 20, 20), 'A')), range(0, 4))) . ".\n\n";
$md .= "## H02 — bootstrap of the proposed ESTABLISHED standing from a fresh store (14 days, 100 browsers)\n\n";
$md .= "NEW browsers: REAL MAIN pipeline (M0 inputs, up to 3 retries). ESTABLISHED browsers: **IDEALIZED** — assumed admitted and absent from the shared sets; not measured.\n\n";
$md .= $row(['Variant', 'established at end of day 1…14', 'newcomers with no login, day 1…14', 'attacker reach / day']) . "|---|---|---|---|\n";
foreach (H02_VARIANT_NAMES as $vv) {
    $md .= $row([$vv, implode(' ', $series($vv, 'establishedAtEnd')), implode(' ', $series($vv, 'newcomersNoLoginToday')),
        in_array($vv, ['ATK', 'ATKM'], true) ? implode(' ', $series($vv, 'attackerReach')) : '—']);
}
$md .= "\n## Descriptive observations (facts about MAIN and the stated H02 model — not gates of a candidate)\n\n" . $row(['ID', 'Source', 'Result', 'Observation', 'Evidence']) . "|---|---|---|---|---|\n";
foreach ($obs as [$id, $src, $title, $ok, $evidence]) {
    $md .= $row([$id, $src, $ok ? 'REPRODUCED' : '**NOT_REPRODUCED**', $title, $evidence]);
}

if (! is_dir($outDir) && ! mkdir($outDir, 0777, true)) {
    invalid("cannot create $outDir");
}
file_put_contents("$outDir/summary.md", $md);
file_put_contents("$outDir/observations.jsonl", $jsonl);
$bad = array_filter($obs, static fn(array $o): bool => ! $o[3]);
echo 'observations: ' . (count($obs) - count($bad)) . '/' . count($obs) . " reproduced\n";
exit($bad === [] ? 0 : 6);
