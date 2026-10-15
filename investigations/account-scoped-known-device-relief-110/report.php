<?php

declare(strict_types=1);

// Fail-closed evidence validation and generated summary for the AKDR study.
//
//   php report.php <v01.jsonl> <o01.jsonl> <b01.jsonl> <outDir>
//
// Exit 0: evidence valid, every observation REPRODUCED. Exit 5: invalid evidence (schema, provenance, completeness, a trace / token that
// breaks the replay rules, a stored metric that differs from the value re-derived from the archived per-request evidence, a failed gate).
// Exit 6: evidence valid but an observation is NOT_REPRODUCED.
//
// Integrity scope: this validator proves that every archived number is consistent with the archived per-request evidence and the
// deterministic schedule (rebuilt from lib/akdr.php). That each archived decision is what the pinned runtime returns is shown by re-running
// the scenarios (run.sh byte-diffs them against expected/).
require __DIR__ . '/lib/akdr.php';

if ($argc !== 5) {
    fwrite(STDERR, "usage: report.php v01.jsonl o01.jsonl b01.jsonl outDir\n");
    exit(2);
}

function bad(string $msg): never
{
    fwrite(STDERR, "INVALID EVIDENCE: $msg\n");
    exit(5);
}

/** @return list<array<string, mixed>> */
function loadJsonl(string $path, string $scenario): array
{
    if (! is_file($path) || filesize($path) === 0) {
        bad("$scenario: missing or empty $path");
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $n => $line) {
        try {
            $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            bad("$scenario: malformed JSON on line " . ($n + 1));
        }
        if (! is_array($r) || ($r['s'] ?? null) !== $scenario || ! isset($r['row'])) {
            bad("$scenario: mislabelled row on line " . ($n + 1));
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
        bad("$where: fields " . implode(',', array_diff($have, $keys)) . ' unexpected / ' . implode(',', array_diff($keys, $have)) . ' missing');
    }
}

function checkProvenance(array $rows, string $scenario): void
{
    $p = array_values(array_filter($rows, static fn(array $r): bool => $r['row'] === 'provenance'));
    if (count($p) !== 1) {
        bad("$scenario: expected exactly one provenance row");
    }
    $p = $p[0];
    $want = [];
    foreach (AKDR_RUNTIME_SHA256 as $class => $sha) {
        $want[substr($class, strrpos($class, '\\') + 1)] = $sha;
    }
    if (($p['sha256'] ?? null) !== $want || ($p['baseSha'] ?? null) !== AKDR_BASELINE_SHA || ($p['patched'] ?? true) !== false
        || ($p['level'] ?? null) !== 'MAIN_RUNTIME_OBSERVED + RELIEF_STORE_VIEW_MODEL' || ($p['cycle'] ?? null) !== akdrCycleConstants()) {
        bad("$scenario: provenance does not match the pinned runtime");
    }
}

[$_, $vPath, $oPath, $bPath, $outDir] = $argv;
$V = loadJsonl($vPath, 'V01');
$O = loadJsonl($oPath, 'O01');
$B = loadJsonl($bPath, 'B01');
foreach ([[$V, 'V01'], [$O, 'O01'], [$B, 'B01']] as [$rows, $sc]) {
    checkProvenance($rows, $sc);
}

// =======================================================================================================================================
// V01
// =======================================================================================================================================
$streams = akdrVStreams();
$vCellKeys = ['s', 'row', 'stream', 'policy', 'flow', 'variant', 'class', 'compliant', 'legitAttempts', 'legitDenied', 'legitUsersDenied', 'attackAttempts', 'attackRelieved', 'attackReached', 'attackDenied', 'attackAccountsReached', 'attackMaxPerAccount', 'attackReachedFirst600', 'legitDeniedBySource', 'attackDeniedBySource', 'blockIssuances', 'check', 'record', 'relieved'];
$expectedV = [];
foreach (akdrVCells() as [$id, $policy, $flow, $variant]) {
    $expectedV["$id|$policy|$flow|$variant"] = true;
}
$vc = [];
$gates = [];
$streamGates = [];
foreach ($V as $r) {
    if ($r['row'] === 'provenance') {
        continue;
    }
    if ($r['row'] === 'gate') {
        if ($r['gate'] === 'G-STREAM') {
            exactKeys($r, ['s', 'row', 'gate', 'stream', 'ok', 'compliant', 'knownAttackRequests', 'devices', 'devicesBoundToSeveralAccounts'], 'V01 G-STREAM');
            if (isset($streamGates[$r['stream']]) || ! isset($streams[$r['stream']])) {
                bad("V01: duplicate or unknown G-STREAM row {$r['stream']}");
            }
            $streamGates[$r['stream']] = $r;
        } elseif (in_array($r['gate'], ['G-EQ', 'G-SUPP'], true)) {
            exactKeys($r, $r['gate'] === 'G-EQ' ? ['s', 'row', 'gate', 'ok', 'cells', 'differingRequests'] : ['s', 'row', 'gate', 'ok', 'relievedDenials', 'relievedDeniedByRelievedScopesOnly'], "V01 {$r['gate']}");
            if (isset($gates[$r['gate']])) {
                bad("V01: duplicate gate {$r['gate']}");
            }
            $gates[$r['gate']] = $r;
        } else {
            bad("V01: unknown gate {$r['gate']}");
        }
        continue;
    }
    if ($r['row'] !== 'cell') {
        bad("V01: unknown row kind {$r['row']}");
    }
    exactKeys($r, $vCellKeys, 'V01 cell');
    $k = "{$r['stream']}|{$r['policy']}|{$r['flow']}|{$r['variant']}";
    if (! isset($expectedV[$k]) || isset($vc[$k])) {
        bad("V01: unknown or duplicate cell $k");
    }
    $vc[$k] = $r;
}
if (count($vc) !== count($expectedV)) {
    bad('V01: missing cells (' . count($vc) . ' of ' . count($expectedV) . ')');
}
if (count($streamGates) !== count($streams)) {
    bad('V01: G-STREAM rows missing');
}
$eqCellsWant = count($streams) * 2 * 3;
if (! isset($gates['G-EQ'], $gates['G-SUPP']) || $gates['G-EQ']['ok'] !== true || $gates['G-EQ']['differingRequests'] !== 0 || $gates['G-EQ']['cells'] !== $eqCellsWant
    || $gates['G-SUPP']['ok'] !== true || $gates['G-SUPP']['relievedDeniedByRelievedScopesOnly'] !== 0) {
    bad('V01: gate G-EQ or G-SUPP failed or incomplete');
}
foreach ($streamGates as $id => $g) {
    if ($g['ok'] !== true || $g['compliant'] !== $streams[$id]['compliant']) {
        bad("V01: G-STREAM failed for $id");
    }
}

/** Re-derive one V cell from its trace and the deterministic schedule. @return array<string, mixed> */
function deriveV(array $r, array $events): array
{
    $n = count($events);
    foreach (['check', 'record', 'relieved'] as $f) {
        if (strlen($r[$f]) !== $n) {
            bad("V01 {$r['stream']}/{$r['variant']}/{$r['flow']}: $f length " . strlen($r[$f]) . " != $n events");
        }
    }
    $d = ['legitAttempts' => 0, 'legitDenied' => 0, 'attackAttempts' => 0, 'attackRelieved' => 0, 'attackReached' => 0, 'attackDenied' => 0, 'attackReachedFirst600' => 0];
    $per = [];
    $legWho = [];
    $byT = [];
    foreach ($events as $i => $e) {
        $c = $r['check'][$i];
        $rec = $r['record'][$i];
        $rel = $r['relieved'][$i];
        if (strpbrk($c . $rec, 'ASH-') === false || ! in_array($c, ['A', 'S', 'H', '-'], true) || ! in_array($rec, ['A', 'S', 'H', '-'], true) || ! in_array($rel, ['R', '-'], true)) {
            bad("V01 {$r['stream']}: character outside the decision alphabet at $i");
        }
        $flow = $r['flow'];
        if ($flow === 'pre' && ($c === '-' || $rec !== '-')) {
            bad("V01 {$r['stream']}/{$r['variant']}: pre flow must have a check and no record at $i");
        }
        if ($flow === 'out' && ($c !== '-' || $rec === '-')) {
            bad("V01 {$r['stream']}/{$r['variant']}: out flow must have a record and no check at $i");
        }
        if ($flow === 'pre+out' && ($c === '-' || ($c === 'A') === ($rec === '-'))) {
            bad("V01 {$r['stream']}/{$r['variant']}: pre+out record must follow exactly the admitted checks at $i");
        }
        $known = akdrIsKnown(k1Ctx($e, 'login'));
        if ($rel === 'R' && ($r['variant'] === 'MAIN' || ! $known)) {
            bad("V01 {$r['stream']}/{$r['variant']}: relief on a request that is not eligible at $i");
        }
        if ($rel === '-' && $known && in_array($r['variant'], ['A', 'B', 'C1', 'C2', 'C3'], true)) {
            bad("V01 {$r['stream']}/{$r['variant']}: an ungated variant left a known request unrelieved at $i");
        }
        $blocked = ($c !== '-' && $c !== 'A') || ($rec !== '-' && $rec !== 'A');
        $reached = $flow === 'out' ? true : $c === 'A';
        if ($e['kind'] === 'attack') {
            $d['attackAttempts']++;
            $d['attackRelieved'] += $rel === 'R' ? 1 : 0;
            if ($reached) {
                $d['attackReached']++;
                $per[(string) $e['acct']] = ($per[(string) $e['acct']] ?? 0) + 1;
                $d['attackReachedFirst600'] += $e['t'] < 600 ? 1 : 0;
                $byT[] = $e['t'];
            }
            $d['attackDenied'] += $blocked ? 1 : 0;
        } else {
            $d['legitAttempts']++;
            if ($blocked) {
                $d['legitDenied']++;
                $legWho[(string) $e['who']] = true;
            }
        }
    }
    $d['legitUsersDenied'] = count($legWho);
    $d['attackAccountsReached'] = count($per);
    $d['attackMaxPerAccount'] = $per === [] ? 0 : max($per);
    foreach ($d as $f => $v) {
        if ($r[$f] !== $v) {
            bad("V01 {$r['stream']}/{$r['policy']}/{$r['flow']}/{$r['variant']}: $f = {$r[$f]} but the trace gives $v");
        }
    }
    if (array_sum($r['legitDeniedBySource']) !== $d['legitDenied'] || array_sum($r['attackDeniedBySource']) !== $d['attackDenied']) {
        bad("V01 {$r['stream']}/{$r['variant']}: denial attribution does not sum to the denials");
    }
    if ($r['class'] !== $GLOBALS['streams'][$r['stream']]['class'] || $r['compliant'] !== $GLOBALS['streams'][$r['stream']]['compliant']) {
        bad("V01 {$r['stream']}: class / compliance label differs from the stream definition");
    }

    return ['reachTimes' => $byT];
}

$reachTimes = [];
foreach ($vc as $k => $r) {
    $reachTimes[$k] = deriveV($r, $streams[$r['stream']]['events'])['reachTimes'];
}

// G-INERT (V): with no known request in a stream, every variant must give MAIN's decisions exactly.
foreach ($vc as $k => $r) {
    $hasKnown = false;
    foreach ($streams[$r['stream']]['events'] as $e) {
        $hasKnown = $hasKnown || akdrIsKnown(k1Ctx($e, 'login'));
    }
    $m = $vc["{$r['stream']}|{$r['policy']}|{$r['flow']}|MAIN"];
    if (! $hasKnown && ($r['check'] !== $m['check'] || $r['record'] !== $m['record'])) {
        bad("G-INERT: {$r['stream']}/{$r['variant']} differs from MAIN without any known request");
    }
}

// Prior #113 S0 evidence (READ, never re-run): provenance must be the S0 pipeline.
$s0Path = dirname(__DIR__) . '/trusted-multi-session-s0/expected/t01_matrix.jsonl';
$s0 = [];
foreach (file($s0Path, FILE_IGNORE_NEW_LINES) as $line) {
    $x = json_decode($line, true);
    if (($x['s'] ?? '') === 'T01.provenance' && (($x['pipelineSha256'] ?? '') !== '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc' || ($x['level'] ?? '') !== 'S0_RUNTIME_OBSERVED')) {
        bad('prior #113 evidence is not the S0 pipeline');
    }
    if (($x['s'] ?? '') === 'T01.matrix') {
        $s0["{$x['stream']}|{$x['shape']}|{$x['flow']}"] = $x;
    }
}

// =======================================================================================================================================
// O01 and B01 tokens
// =======================================================================================================================================

/**
 * Replay one office day's tokens. Validates the per-browser transitions and returns the re-derived counters.
 *
 * @return array<string, mixed>
 */
function replayOffice(string $tokens, int $n, string $known, string $attacker, string $variant, string $where): array
{
    if (strlen($known) !== $n || strspn($known, '01') !== $n) {
        bad("$where: known set malformed");
    }
    $u = [];
    $atk = ['n' => 0, 'reached' => 0, 'acc' => []];
    $relieved = 0;
    $deniedAttempts = 0;
    $atkDenied = 0;
    $lastT = -1;
    foreach ($tokens === '' ? [] : explode(' ', $tokens) as $tk) {
        if (preg_match('/^([NK])(\d+)@(\d+)([ASH])([ASH-])([R-])$/', $tk, $m)) {
            [$all, $who, $idx, $t, $c, $rec, $rel] = $m;
            $t = (int) $t;
            if ($who !== ($attacker === 'NC' ? 'N' : 'K') || $attacker === 'none') {
                bad("$where: attacker token $tk without that attacker");
            }
            if (($c === 'A') === ($rec === '-') || ($rel === 'R' && ($who === 'N' || $variant === 'MAIN'))) {
                bad("$where: attacker token $tk breaks the flow / relief rules");
            }
            $atk['n']++;
            if ($c === 'A') {
                $atk['reached']++;
                $atk['acc']["$who$idx"] = ($atk['acc']["$who$idx"] ?? 0) + 1;
            }
            // blocked = denied at check, or answered with a block by the recorded outcome (same definition as the engine)
            $atkDenied += ($c !== 'A' || ($rec !== '-' && $rec !== 'A')) ? 1 : 0;
            $relieved += $rel === 'R' ? 1 : 0;
        } elseif (preg_match('/^(\d+)@(\d+)([of])(\d)([ASH])([ASH-])([R-])$/', $tk, $m)) {
            [$all, $idx, $t, $o, $try, $c, $rec, $rel] = $m;
            $idx = (int) $idx;
            $t = (int) $t;
            $try = (int) $try;
            if ($idx >= $n || ($c === 'A') === ($rec === '-')) {
                bad("$where: browser token $tk out of range or breaks pre+out");
            }
            if ($rel === 'R' && ($variant === 'MAIN' || $known[$idx] !== '1')) {
                bad("$where: relief on an unknown browser / MAIN in $tk");
            }
            if ($rel === '-' && $known[$idx] === '1' && in_array($variant, ['A', 'B', 'C1', 'C2', 'C3'], true)) {
                bad("$where: ungated variant left known browser $idx unrelieved");
            }
            $s = $u[$idx] ?? null;
            if ($s === null) {
                if ($try !== 0) {
                    bad("$where: browser $idx starts with retry $try");
                }
                $s = ['first' => $t, 'success' => null, 'denied' => 0, 'retries' => 0, 'last' => null];
            } else {
                if ($s['success'] !== null) {
                    bad("$where: browser $idx has a request after its success");
                }
                [$pt, $po, $ptry, $pc] = $s['last'];
                if ($pc !== 'A') {
                    // a retry of the same logical attempt, after max(retryAfter, 60) s
                    if ($o !== $po || $try !== $ptry + 1 || $t < $pt + 60 || $try > 3) {
                        bad("$where: browser $idx retry rule broken at $tk");
                    }
                    $s['retries']++;
                } elseif ($po === 'f') {
                    if ($o !== 'o' || $try !== $ptry || $t !== $pt + 15) {
                        bad("$where: browser $idx typo follow-up broken at $tk");
                    }
                } else {
                    bad("$where: browser $idx continues after an admitted success");
                }
            }
            $deniedAttempts += ($c !== 'A' || ($rec !== '-' && $rec !== 'A')) ? 1 : 0;
            if ($c !== 'A') {
                $s['denied']++;
            } elseif ($o === 'o') {
                $s['success'] = $t;
            }
            $s['last'] = [$t, $o, $try, $c];
            $u[$idx] = $s;
            $relieved += $rel === 'R' ? 1 : 0;
        } else {
            bad("$where: malformed token $tk");
        }
    }
    if (count($u) !== $n) {
        bad("$where: " . count($u) . " of $n browsers present");
    }
    $locked = ['known' => 0, 'unknown' => 0];
    $deniedUsers = ['known' => 0, 'unknown' => 0];
    $delay = 0;
    $succ = [];
    foreach ($u as $idx => $s) {
        $side = $known[$idx] === '1' ? 'known' : 'unknown';
        if ($s['success'] === null) {
            if ($s['last'][3] === 'A' || $s['retries'] < 3) {
                bad("$where: browser $idx stopped without success before its 3 retries");
            }
            $locked[$side]++;
        } else {
            $delay += $s['success'] - $s['first'];
            $succ[$idx] = true;
        }
        $deniedUsers[$side] += $s['denied'] > 0 ? 1 : 0;
    }
    ksort($atk['acc']);

    return ['locked' => $locked, 'deniedUsers' => $deniedUsers, 'deniedAttempts' => $deniedAttempts, 'atkDenied' => $atkDenied, 'relieved' => $relieved,
        'atk' => $atk, 'delay' => $delay, 'successes' => count($succ), 'succ' => $succ];
}

$expectedO = [];
foreach (akdrOCells() as [$n, $mode, $pct, $atk, $v]) {
    $expectedO["$n|$mode|$pct|$atk|$v"] = true;
}
$oc = [];
foreach ($O as $r) {
    if ($r['row'] === 'provenance') {
        continue;
    }
    if ($r['row'] !== 'cell') {
        bad("O01: unknown row {$r['row']}");
    }
    exactKeys($r, ['s', 'row', 'size', 'mode', 'knownPct', 'attacker', 'variant', 'known', 'relievedRequests', 'attackReached', 'attackAttempts', 'attackAccounts', 'attackMaxPerAccount', 'causes', 'tokens'], 'O01 cell');
    $k = "{$r['size']}|{$r['mode']}|{$r['knownPct']}|{$r['attacker']}|{$r['variant']}";
    if (! isset($expectedO[$k]) || isset($oc[$k])) {
        bad("O01: unknown or duplicate cell $k");
    }
    $want = implode('', array_map(static fn(bool $x): string => $x ? '1' : '0', akdrKnownSet($r['size'], $r['knownPct'], 4242 + $r['size'])));
    if ($r['known'] !== $want) {
        bad("O01 $k: known set differs from the deterministic definition");
    }
    $d = replayOffice($r['tokens'], $r['size'], $r['known'], $r['attacker'], $r['variant'], "O01 $k");
    $max = $d['atk']['acc'] === [] ? 0 : max($d['atk']['acc']);
    if ($d['relieved'] !== $r['relievedRequests'] || $d['atk']['reached'] !== $r['attackReached'] || $d['atk']['n'] !== $r['attackAttempts']
        || count($d['atk']['acc']) !== $r['attackAccounts'] || $max !== $r['attackMaxPerAccount']
        || array_sum($r['causes']['legit']) !== $d['deniedAttempts'] || array_sum($r['causes']['attack']) !== $d['atkDenied']) {
        bad("O01 $k: stored counters differ from the token replay");
    }
    $oc[$k] = $r + ['d' => $d];
}
if (count($oc) !== count($expectedO)) {
    bad('O01: missing cells (' . count($oc) . ' of ' . count($expectedO) . ')');
}
// G-INERT (O): with no known browser and no known attacker, every variant equals MAIN token for token.
foreach ($oc as $k => $r) {
    if ($r['knownPct'] === 0 && $r['attacker'] !== 'KC' && $r['tokens'] !== $oc["{$r['size']}|{$r['mode']}|0|{$r['attacker']}|MAIN"]['tokens']) {
        bad("G-INERT (O01): $k differs from MAIN without any known request");
    }
}

$bc = [];
foreach ($B as $r) {
    if ($r['row'] === 'provenance') {
        continue;
    }
    if ($r['row'] !== 'day') {
        bad("B01: unknown row {$r['row']}");
    }
    exactKeys($r, ['s', 'row', 'run', 'mode', 'variant', 'attacker', 'day', 'startKnown', 'attackReached', 'attackAttempts', 'causes', 'tokens'], 'B01 day');
    if (! isset(AKDR_B_RUNS[$r['run']]) || AKDR_B_RUNS[$r['run']] !== [$r['variant'], $r['attacker']] || ! in_array($r['mode'], ['LOW', 'MED'], true)
        || $r['day'] < 0 || $r['day'] >= AKDR_B_DAYS) {
        bad('B01: unknown run / mode / day');
    }
    $k = "{$r['mode']}|{$r['run']}|{$r['day']}";
    if (isset($bc[$k])) {
        bad("B01: duplicate $k");
    }
    $d = replayOffice($r['tokens'], AKDR_B_SIZE, $r['startKnown'], $r['attacker'], $r['variant'], "B01 $k");
    if ($d['atk']['reached'] !== $r['attackReached'] || $d['atk']['n'] !== $r['attackAttempts']
        || array_sum($r['causes']['legit']) !== $d['deniedAttempts'] || array_sum($r['causes']['attack']) !== $d['atkDenied']) {
        bad("B01 $k: stored counters differ from the token replay");
    }
    $bc[$k] = $r + ['d' => $d];
}
foreach (['LOW', 'MED'] as $mode) {
    foreach (array_keys(AKDR_B_RUNS) as $run) {
        $carry = str_repeat('0', AKDR_B_SIZE);
        for ($day = 0; $day < AKDR_B_DAYS; $day++) {
            $k = "$mode|$run|$day";
            if (! isset($bc[$k])) {
                bad("B01: missing $k");
            }
            if ($bc[$k]['startKnown'] !== $carry) {
                bad("B01 $k: start-of-day known set is not the carried establishment set");
            }
            foreach (array_keys($bc[$k]['d']['succ']) as $idx) {
                $carry[$idx] = '1';
            }
        }
        // G-INERT (B): day 0 has no known browser, so it equals the MAIN run with the same attacker.
        $mainRun = AKDR_B_RUNS[$run][1] === 'NC' ? 'MAIN+NC' : 'MAIN';
        if ($bc["$mode|$run|0"]['tokens'] !== $bc["$mode|$mainRun|0"]['tokens']) {
            bad("G-INERT (B01): $mode $run day 0 differs from $mainRun");
        }
    }
}
if (count($bc) !== 2 * count(AKDR_B_RUNS) * AKDR_B_DAYS) {
    bad('B01: unexpected day rows');
}

// =======================================================================================================================================
// Derived views
// =======================================================================================================================================
$variants = array_keys(AKDR_VARIANTS);
$cell = static fn(string $id, string $flow, string $v, string $policy = 'login'): array => $vc["$id|$policy|$flow|$v"];
$reach = static fn(string $id, string $flow, string $v, string $policy = 'login'): int => $vc["$id|$policy|$flow|$v"]['attackReached'];

/** Worst-window absolute delta of a variant's attacker reach over MAIN (aligned windows of $w s). */
$windowDelta = static function (string $id, string $flow, string $v, int $w, string $policy = 'login') use ($reachTimes): int {
    $bucket = static function (array $ts) use ($w): array {
        $b = [];
        foreach ($ts as $t) {
            $b[intdiv($t, $w)] = ($b[intdiv($t, $w)] ?? 0) + 1;
        }

        return $b;
    };
    $m = $bucket($reachTimes["$id|$policy|$flow|MAIN"]);
    $x = $bucket($reachTimes["$id|$policy|$flow|$v"]);
    $best = PHP_INT_MIN;
    foreach (array_unique(array_merge(array_keys($m), array_keys($x))) as $k) {
        $best = max($best, ($x[$k] ?? 0) - ($m[$k] ?? 0));
    }

    return $best === PHP_INT_MIN ? 0 : $best;
};
$oLocked = static fn(int $n, string $mode, int $pct, string $atk, string $v): array => $oc["$n|$mode|$pct|$atk|$v"]['d']['locked'];
$bLockedSeries = static function (string $mode, string $run) use ($bc): array {
    $out = [];
    for ($day = 0; $day < AKDR_B_DAYS; $day++) {
        $d = $bc["$mode|$run|$day"]['d'];
        $out[] = $d['locked']['known'] + $d['locked']['unknown'];
    }

    return $out;
};
$bSeries = static function (string $mode, string $run, string $what) use ($bc): array {
    $out = [];
    for ($day = 0; $day < AKDR_B_DAYS; $day++) {
        $out[] = match ($what) {
            'known' => substr_count($bc["$mode|$run|$day"]['startKnown'], '1'),
            'atk' => $bc["$mode|$run|$day"]['attackReached'],
        };
    }

    return $out;
};

// =======================================================================================================================================
// Observations (each a predicate over the re-derived evidence; REPRODUCED or NOT_REPRODUCED)
// =======================================================================================================================================
$obs = [];
$o = static function (string $id, string $kind, string $statement, bool $holds, string $evidence) use (&$obs): void {
    $obs[] = ['id' => $id, 'kind' => $kind, 'status' => $holds ? 'REPRODUCED' : 'NOT_REPRODUCED', 'statement' => $statement, 'evidence' => $evidence];
};
$reliefSet = ['A', 'C1', 'C2', 'C3', 'D1'];
$full = ['C1', 'C2', 'C3', 'D1'];

$s0r = static fn(string $id, string $flow): int => $s0["$id|login|$flow"]['attackReachedVerification'];
$o('M1', 'MAIN', 'Main keeps the #113 cross-account bound of the multi-session attacker: TMSd and TMS reach the verifier exactly as on S0 in pre+out and pre',
    $reach('TMSd', 'pre+out', 'MAIN') === $s0r('TMSd', 'pre+out') && $reach('TMS', 'pre+out', 'MAIN') === $s0r('TMS', 'pre+out')
    && $reach('TMSd', 'pre', 'MAIN') === $s0r('TMSd', 'pre') && $reach('TMS', 'pre', 'MAIN') === $s0r('TMS', 'pre'),
    sprintf('TMSd S0 %d / main %d; TMS S0 %d / main %d (pre+out); pre: TMSd %d/%d, TMS %d/%d', $s0r('TMSd', 'pre+out'), $reach('TMSd', 'pre+out', 'MAIN'), $s0r('TMS', 'pre+out'), $reach('TMS', 'pre+out', 'MAIN'), $s0r('TMSd', 'pre'), $reach('TMSd', 'pre', 'MAIN'), $s0r('TMS', 'pre'), $reach('TMS', 'pre', 'MAIN')));
$o('M2', 'MAIN', 'DEC-021 changed the Host-violating single-session controls on main (pre+out) but not check-only: TMX and TMXb are no longer fully reached in pre+out; they still are in pre',
    $reach('TMX', 'pre+out', 'MAIN') < $s0r('TMX', 'pre+out') && $reach('TMXb', 'pre+out', 'MAIN') < $s0r('TMXb', 'pre+out')
    && $reach('TMX', 'pre', 'MAIN') === 200 && $reach('TMXb', 'pre', 'MAIN') === 300,
    sprintf('TMX S0 %d -> main %d; TMXb S0 %d -> main %d (pre+out); pre main TMX %d/200, TMXb %d/300', $s0r('TMX', 'pre+out'), $reach('TMX', 'pre+out', 'MAIN'), $s0r('TMXb', 'pre+out'), $reach('TMXb', 'pre+out', 'MAIN'), $reach('TMX', 'pre', 'MAIN'), $reach('TMXb', 'pre', 'MAIN')));
$tmsEqual = true;
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $a = $cell('N12T', $flow, 'MAIN');
    $b = $cell('TMS', $flow, 'MAIN');
    $tmsEqual = $tmsEqual && $a['check'] === $b['check'] && $a['record'] === $b['record'];
}
$o('M3', 'MAIN', 'On main the trusted office N12T and the 12-session attacker TMS still receive identical decisions request for request in all three flows',
    $tmsEqual, 'check / record strings of N12T and TMS identical (pre+out, pre, out)');
$o('R1', 'MODEL', 'Every variant that relieves K2 for known devices frees the trusted office N12T completely (pre+out)',
    array_reduce($reliefSet, static fn(bool $c, string $v): bool => $c && $cell('N12T', 'pre+out', $v)['legitDenied'] === 0, true) && $cell('N12T', 'pre+out', 'MAIN')['legitDenied'] > 0,
    sprintf('N12T legit denied: MAIN %d; %s', $cell('N12T', 'pre+out', 'MAIN')['legitDenied'], implode(', ', array_map(static fn(string $v): string => "$v " . $cell('N12T', 'pre+out', $v)['legitDenied'], $variants))));
$o('R2', 'MODEL', 'Indistinguishability carries over: in every variant where N12T is fully relieved, the TMS attacker (same requests, failures instead of successes) reaches the verifier on every attempt',
    array_reduce($variants, static fn(bool $c, string $v): bool => $c && ($cell('N12T', 'pre+out', $v)['legitDenied'] !== 0 || $reach('TMS', 'pre+out', $v) === 61), true),
    implode(', ', array_map(static fn(string $v): string => "$v N12T denied " . $cell('N12T', 'pre+out', $v)['legitDenied'] . ' / TMS reach ' . $reach('TMS', 'pre+out', $v) . '/61', $variants)));
$o('R3', 'MODEL', 'The multi-session attacker regains reach on every account it holds a known device for: TMSd pre+out rises above main in every full relief variant and touches all 12 accounts',
    array_reduce($full, static fn(bool $c, string $v): bool => $c && $reach('TMSd', 'pre+out', $v) > $reach('TMSd', 'pre+out', 'MAIN') && $cell('TMSd', 'pre+out', $v)['attackAccountsReached'] === 12, true),
    sprintf('TMSd reach MAIN %d (max %d/account); %s', $reach('TMSd', 'pre+out', 'MAIN'), $cell('TMSd', 'pre+out', 'MAIN')['attackMaxPerAccount'], implode(', ', array_map(static fn(string $v): string => "$v " . $reach('TMSd', 'pre+out', $v) . ' (max ' . $cell('TMSd', 'pre+out', $v)['attackMaxPerAccount'] . ')', $full))));
$o('R4', 'MODEL', 'Check-only Hosts under the modelled variants (no pre-check charge): with K1/K2 relieved nothing bounds a known-device attacker any more — complete reach on long streams main protects (TMSd, D24T, VMSd)',
    array_reduce($full, static fn(bool $c, string $v): bool => $c && $reach('TMSd', 'pre', $v) === 200 && $reach('D24T', 'pre', $v) === 2880 && $reach('VMSd', 'pre', $v) === 200, true)
    && $reach('TMSd', 'pre', 'MAIN') < 200 && $reach('D24T', 'pre', 'MAIN') < 2880,
    sprintf('pre: TMSd MAIN %d -> %s; D24T MAIN %d -> %s', $reach('TMSd', 'pre', 'MAIN'), implode('/', array_map(static fn(string $v): int => $reach('TMSd', 'pre', $v), $full)), $reach('D24T', 'pre', 'MAIN'), implode('/', array_map(static fn(string $v): int => $reach('D24T', 'pre', $v), $full))));
$o('R5', 'MODEL', 'Over 24 h (12 trusted sessions, 1 / 30 s, pre+out) every full relief variant raises the attacker\'s verifier reach above main; the allowance gate (D1) reduces but does not remove the increase',
    array_reduce($full, static fn(bool $c, string $v): bool => $c && $reach('D24T', 'pre+out', $v) > $reach('D24T', 'pre+out', 'MAIN'), true)
    && $reach('D24T', 'pre+out', 'D1') < $reach('D24T', 'pre+out', 'C1'),
    sprintf('D24T reach/day MAIN %d; %s', $reach('D24T', 'pre+out', 'MAIN'), implode(', ', array_map(static fn(string $v): string => "$v " . $reach('D24T', 'pre+out', $v) . ' (max ' . $cell('D24T', 'pre+out', $v)['attackMaxPerAccount'] . '/account)', $variants))));
$o('R6', 'MODEL', 'Attacker gain grows with the number of accounts held (KS1 < KS2 < KS5 < TMSd < KS50 under C1, pre+out); main stays flat from two accounts on',
    $reach('KS1', 'pre+out', 'C1') < $reach('KS2', 'pre+out', 'C1') && $reach('KS2', 'pre+out', 'C1') < $reach('KS5', 'pre+out', 'C1')
    && $reach('KS5', 'pre+out', 'C1') < $reach('TMSd', 'pre+out', 'C1') && $reach('TMSd', 'pre+out', 'C1') < $reach('KS50', 'pre+out', 'C1')
    && $reach('KS2', 'pre+out', 'MAIN') === $reach('KS50', 'pre+out', 'MAIN'),
    sprintf('C1: KS1 %d, KS2 %d, KS5 %d, TMSd(12) %d, KS50 %d; MAIN: %d, %d, %d, %d, %d', $reach('KS1', 'pre+out', 'C1'), $reach('KS2', 'pre+out', 'C1'), $reach('KS5', 'pre+out', 'C1'), $reach('TMSd', 'pre+out', 'C1'), $reach('KS50', 'pre+out', 'C1'), $reach('KS1', 'pre+out', 'MAIN'), $reach('KS2', 'pre+out', 'MAIN'), $reach('KS5', 'pre+out', 'MAIN'), $reach('TMSd', 'pre+out', 'MAIN'), $reach('KS50', 'pre+out', 'MAIN')));
$o('R7', 'MODEL', 'One account: DEC-021 still bounds the known device per account under relief (KS1, D24K1 unchanged; several devices on one account MD3 / MD10 gain at most one attempt)',
    array_reduce($variants, static fn(bool $c, string $v): bool => $c && $reach('KS1', 'pre+out', $v) === $reach('KS1', 'pre+out', 'MAIN') && $reach('D24K1', 'pre+out', $v) === $reach('D24K1', 'pre+out', 'MAIN')
        && $reach('MD3', 'pre+out', $v) - $reach('MD3', 'pre+out', 'MAIN') <= 1 && $reach('MD10', 'pre+out', $v) - $reach('MD10', 'pre+out', 'MAIN') <= 1, true),
    sprintf('KS1 %d; D24K1 %d/day; MD3 MAIN %d / C1 %d; MD10 MAIN %d / C1 %d', $reach('KS1', 'pre+out', 'MAIN'), $reach('D24K1', 'pre+out', 'MAIN'), $reach('MD3', 'pre+out', 'MAIN'), $reach('MD3', 'pre+out', 'C1'), $reach('MD10', 'pre+out', 'MAIN'), $reach('MD10', 'pre+out', 'C1')));
$o('R8', 'MODEL', 'Host-contract violation control (integration risk, not compliant traffic): a Host that sends the verified flag for accounts the device was never verified for (SPV) turns K1 relief into a spray bypass reaching all 200 fresh accounts under B / C / D1; reusing one account\'s proof for others (VMX) is bounded only per account',
    array_reduce(['B', 'C1', 'C2', 'C3', 'D1'], static fn(bool $c, string $v): bool => $c && $reach('SPV', 'pre+out', $v) === 200 && $reach('VMX', 'pre+out', $v) > $reach('VMX', 'pre+out', 'MAIN'), true),
    sprintf('SPV MAIN %d -> C1 %d; VMX MAIN %d -> C1 %d', $reach('SPV', 'pre+out', 'MAIN'), $reach('SPV', 'pre+out', 'C1'), $reach('VMX', 'pre+out', 'MAIN'), $reach('VMX', 'pre+out', 'C1')));
$o('R9', 'MODEL', 'K1 and K2 relief are not separable for the office: LOW offices need K1 relief (B), MED offices need both (A alone or B alone leaves most known browsers locked)',
    $oLocked(100, 'LOW', 100, 'none', 'B')['known'] === 0 && $oLocked(100, 'LOW', 100, 'none', 'A')['known'] === $oLocked(100, 'LOW', 100, 'none', 'MAIN')['known']
    && $oLocked(100, 'MED', 100, 'none', 'C1')['known'] === 0 && $oLocked(100, 'MED', 100, 'none', 'A')['known'] > 0 && $oLocked(100, 'MED', 100, 'none', 'B')['known'] === $oLocked(100, 'MED', 100, 'none', 'MAIN')['known'],
    sprintf('N100 p100 known locked — LOW: MAIN %d, A %d, B %d; MED: MAIN %d, A %d, B %d, C1 %d', $oLocked(100, 'LOW', 100, 'none', 'MAIN')['known'], $oLocked(100, 'LOW', 100, 'none', 'A')['known'], $oLocked(100, 'LOW', 100, 'none', 'B')['known'], $oLocked(100, 'MED', 100, 'none', 'MAIN')['known'], $oLocked(100, 'MED', 100, 'none', 'A')['known'], $oLocked(100, 'MED', 100, 'none', 'B')['known'], $oLocked(100, 'MED', 100, 'none', 'C1')['known']));
$unknownHelp = true;
$unknownEv = [];
foreach ([50, 100, 250] as $n) {
    foreach (['LOW', 'MED'] as $mode) {
        $m = $oLocked($n, $mode, 90, 'none', 'MAIN')['unknown'];
        $c1 = $oLocked($n, $mode, 90, 'none', 'C1')['unknown'];
        $unknownHelp = $unknownHelp && $oLocked($n, $mode, 90, 'none', 'C1')['known'] === 0 && $c1 >= $m - 2;
        $unknownEv[] = "N$n $mode unknown locked MAIN $m / C1 $c1 of " . intdiv($n * 10 + 50, 100);
    }
}
$o('R10', 'MODEL', 'Relief protects only the known browsers: in a 90 % known office every known browser gets in under C1, while new / cookie-less browsers stay locked out at main\'s rate (within 2)',
    $unknownHelp, implode('; ', $unknownEv));
$o('R11', 'MODEL', 'Pool displacement (B5 mechanism) on the real pipeline: excluding known traffic from the shared sets (C3) raises the credential-less sprayer\'s reach above main; consuming it (C1) stays closer to main',
    $oc['100|LOW|90|NC|C3']['attackReached'] > $oc['100|LOW|90|NC|MAIN']['attackReached'] && $oc['100|LOW|90|NC|C3']['attackReached'] > $oc['100|LOW|90|NC|C1']['attackReached']
    && max($bSeries('LOW', 'C3+NC', 'atk')) > max($bSeries('LOW', 'MAIN+NC', 'atk')),
    sprintf('O01 N100 LOW p90 NC reach: MAIN %d, C1 %d, C2 %d, C3 %d; B01 LOW per day MAIN+NC %s vs C3+NC %s', $oc['100|LOW|90|NC|MAIN']['attackReached'], $oc['100|LOW|90|NC|C1']['attackReached'], $oc['100|LOW|90|NC|C2']['attackReached'], $oc['100|LOW|90|NC|C3']['attackReached'], implode(',', $bSeries('LOW', 'MAIN+NC', 'atk')), implode(',', $bSeries('LOW', 'C3+NC', 'atk'))));
$o('R12', 'MODEL', 'A credentialed known attacker on the office NAT regains reach under every full relief variant (KC, N100 p90)',
    array_reduce($full, static fn(bool $c, string $v): bool => $c && $oc["100|LOW|90|KC|$v"]['attackReached'] > $oc['100|LOW|90|KC|MAIN']['attackReached'] && $oc["100|MED|90|KC|$v"]['attackReached'] > $oc['100|MED|90|KC|MAIN']['attackReached'], true),
    sprintf('KC reach / 200 — LOW: MAIN %d, C1 %d, D1 %d; MED: MAIN %d, C1 %d, D1 %d', $oc['100|LOW|90|KC|MAIN']['attackReached'], $oc['100|LOW|90|KC|C1']['attackReached'], $oc['100|LOW|90|KC|D1']['attackReached'], $oc['100|MED|90|KC|MAIN']['attackReached'], $oc['100|MED|90|KC|C1']['attackReached'], $oc['100|MED|90|KC|D1']['attackReached']));
$bootEq = true;
foreach (['LOW', 'MED'] as $mode) {
    foreach (array_keys(AKDR_B_RUNS) as $run) {
        $bootEq = $bootEq && $bLockedSeries($mode, $run)[0] === $bLockedSeries($mode, AKDR_B_RUNS[$run][1] === 'NC' ? 'MAIN+NC' : 'MAIN')[0];
    }
}
$o('R13', 'MODEL', 'Bootstrap: relief gives nothing on day 0 (no browser has a prior verified login); afterwards it spreads only through main-admitted logins, and on day 13 browsers are still locked out under C1 (more in MED than in LOW)',
    $bootEq && $bLockedSeries('LOW', 'C1')[0] === $bLockedSeries('LOW', 'MAIN')[0] && $bLockedSeries('LOW', 'C1')[AKDR_B_DAYS - 1] > 0
    && $bLockedSeries('MED', 'C1')[AKDR_B_DAYS - 1] > $bLockedSeries('LOW', 'C1')[AKDR_B_DAYS - 1],
    sprintf('locked/day LOW MAIN %s | LOW C1 %s | LOW C3 %s | MED MAIN %s | MED C1 %s', implode(',', $bLockedSeries('LOW', 'MAIN')), implode(',', $bLockedSeries('LOW', 'C1')), implode(',', $bLockedSeries('LOW', 'C3')), implode(',', $bLockedSeries('MED', 'MAIN')), implode(',', $bLockedSeries('MED', 'C1'))));
$o('R14', 'MODEL', 'A cross-account relief cap (D2, R = 5) restores part of the cross-account bound only by withdrawing relief from the office again: both move together',
    $reach('TMSd', 'pre+out', 'D2') < $reach('TMSd', 'pre+out', 'D1') && $cell('N12T', 'pre+out', 'D2')['legitDenied'] > 0
    && $oLocked(100, 'LOW', 100, 'none', 'D2')['known'] > $oLocked(100, 'LOW', 100, 'none', 'D1')['known'],
    sprintf('TMSd D1 %d / D2 %d; N12T denied D1 %d / D2 %d; N100 LOW p100 locked D1 %d / D2 %d', $reach('TMSd', 'pre+out', 'D1'), $reach('TMSd', 'pre+out', 'D2'), $cell('N12T', 'pre+out', 'D1')['legitDenied'], $cell('N12T', 'pre+out', 'D2')['legitDenied'], $oLocked(100, 'LOW', 100, 'none', 'D1')['known'], $oLocked(100, 'LOW', 100, 'none', 'D2')['known']));
$o('R15', 'MODEL', 'OTP: the same structure, smaller per-account bound (allowance 2): TMSd OTP pre+out rises above main under C1 and D1; check-only is complete',
    $reach('TMSd', 'pre+out', 'C1', 'otp') > $reach('TMSd', 'pre+out', 'MAIN', 'otp') && $reach('TMSd', 'pre+out', 'D1', 'otp') > $reach('TMSd', 'pre+out', 'MAIN', 'otp') && $reach('TMSd', 'pre', 'C1', 'otp') === 200,
    sprintf('OTP TMSd MAIN %d, C1 %d, D1 %d (pre+out); pre C1 %d', $reach('TMSd', 'pre+out', 'MAIN', 'otp'), $reach('TMSd', 'pre+out', 'C1', 'otp'), $reach('TMSd', 'pre+out', 'D1', 'otp'), $reach('TMSd', 'pre', 'C1', 'otp')));
$o('R16', 'MAIN', 'Pre-existing main properties surfaced (not caused by relief): a compliant single known device is unbounded for check-only Hosts (D24K1 pre), and on main the K2 cross-account bound is per browser major, so cycling 20 majors (TMUA) multiplies it',
    $reach('D24K1', 'pre', 'MAIN') === 1440 && $reach('TMUA', 'pre+out', 'MAIN') > $reach('TMSd', 'pre+out', 'MAIN') && $reach('TMUA', 'pre+out', 'C1') === $reach('TMUA', 'pre+out', 'MAIN'),
    sprintf('D24K1 pre MAIN %d/1440; TMUA MAIN %d vs TMSd MAIN %d (C1 TMUA %d)', $reach('D24K1', 'pre', 'MAIN'), $reach('TMUA', 'pre+out', 'MAIN'), $reach('TMSd', 'pre+out', 'MAIN'), $reach('TMUA', 'pre+out', 'C1')));

// =======================================================================================================================================
// summary.md
// =======================================================================================================================================
$md = [];
$md[] = '# AKDR — generated evidence summary';
$md[] = '';
$md[] = 'Generated by `report.php` from `v01_known_attackers.jsonl`, `o01_shared_nat.jsonl`, `b01_bootstrap.jsonl`. Do not edit by hand.';
$md[] = '';
$md[] = '* Runtime: unpatched `main@' . AKDR_BASELINE_SHA . '` (sha-256 pinned: EvaluationPipeline `' . substr(AKDR_RUNTIME_SHA256[\Maatify\RateLimiter\Service\EvaluationPipeline::class], 0, 12) . '…`). Reference stores, controlled clock. IPv4, no secret rotation, no Redis, no concurrency.';
$md[] = '* `MAIN` = the runtime as shipped. `A` … `D2` = **store-view MODELS** of relief on that runtime (lib/akdr.php), not a candidate patch.';
$md[] = '* Counts are requests reaching the verifier ("reach"), not people. "max/acct" = most verifier reaches on one account.';
$md[] = '* `[PRIOR S0]` columns are read from the merged #113 evidence and never re-run here.';
$md[] = '';
$md[] = '## 1. Gates';
$md[] = '';
$md[] = '| Gate | Result |';
$md[] = '|---|---|';
$md[] = "| G-EQ (MAIN view ≡ unchanged PR #108 replay) | PASS: {$gates['G-EQ']['cells']} stream×policy×flow cells, {$gates['G-EQ']['differingRequests']} differing requests |";
$md[] = "| G-SUPP (no relieved request denied only by a relieved scope) | PASS: {$gates['G-SUPP']['relievedDenials']} relieved denials, {$gates['G-SUPP']['relievedDeniedByRelievedScopesOnly']} by relieved scopes only |";
$md[] = '| G-STREAM (compliant streams bind each known device to its own account; Host-violating ones do not) | PASS: ' . count($streamGates) . ' streams |';
$md[] = '| G-INERT (no known request ⇒ every variant equals MAIN; V, O and B day 0) | PASS |';
$md[] = '| Trace / token replay (every stored metric re-derived) | PASS: ' . count($vc) . ' V cells, ' . count($oc) . ' O cells, ' . count($bc) . ' B days |';
$md[] = '';

$md[] = '## 2. #113 streams: S0 (prior) vs main (Login, attacker reach / legit denied)';
$md[] = '';
$md[] = '| Stream | S0 pre+out `[PRIOR]` | main pre+out | S0 pre `[PRIOR]` | main pre | S0 out `[PRIOR]` | main out |';
$md[] = '|---|---|---|---|---|---|---|';
foreach (['N12T', 'N12M', 'N12', 'TMS', 'TMSd', 'TMSs', 'TMR', 'TMU', 'TMX', 'TMXb', 'SP5', 'TPAIRT', 'TPAIRM'] as $id) {
    $row = "| $id";
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $p = $s0["$id|login|$flow"];
        $m = $cell($id, $flow, 'MAIN');
        $fmt = static fn(array $x, string $la, string $ld, string $aa, string $ar): string => ($x[$la] > 0 ? "L {$x[$ld]}/{$x[$la]}" : '') . ($x[$la] > 0 && $x[$aa] > 0 ? ' · ' : '') . ($x[$aa] > 0 ? "A {$x[$ar]}/{$x[$aa]}" : '');
        $row .= ' | ' . $fmt($p, 'legitAttempts', 'legitDenied', 'attackAttempts', 'attackReachedVerification') . ' | ' . $fmt($m, 'legitAttempts', 'legitDenied', 'attackAttempts', 'attackReached');
    }
    $md[] = $row . ' |';
}
$md[] = '';
$md[] = 'L = legitimate requests denied; A = attacker requests reaching the verifier.';
$md[] = '';

$md[] = '## 3. Known-device attackers by variant (Login)';
$md[] = '';
foreach (['pre+out', 'pre'] as $flow) {
    $md[] = "### 3." . ($flow === 'pre+out' ? '1' : '2') . " Flow `$flow` — reach (max/acct, accounts reached)";
    $md[] = '';
    $md[] = '| Stream | ' . implode(' | ', $variants) . ' |';
    $md[] = '|---|' . str_repeat('---|', count($variants));
    foreach (['TMS', 'TMSd', 'TMSs', 'TMR', 'TMUA', 'VMSd', 'VMSdL', 'VMR', 'KS1', 'KS2', 'KS5', 'KS50', 'MD3', 'MD10', 'TMX', 'TMXb', 'VMX', 'SP5', 'SPV', 'SP1n', 'TMU', 'D24T', 'D24V', 'D24K1'] as $id) {
        $row = "| $id";
        foreach ($variants as $v) {
            $x = $cell($id, $flow, $v);
            $row .= " | {$x['attackReached']}/{$x['attackAttempts']} ({$x['attackMaxPerAccount']}, {$x['attackAccountsReached']})";
        }
        $md[] = $row . ' |';
    }
    $md[] = '';
}
$md[] = 'Record-only (`out`): every attacker request reaches the verifier in MAIN and C1 alike (the decision follows verification).';
$md[] = '';

$md[] = '## 4. Lead quantification (#110 comment 6156215783): absolute Δ reach over MAIN';
$md[] = '';
$md[] = 'Worst aligned window of 60 / 600 / 1800 s and the whole stream. A model result, not a candidate measurement.';
$md[] = '';
$md[] = '| Stream | Flow | ' . implode(' | ', array_slice($variants, 1)) . ' |';
$md[] = '|---|---|' . str_repeat('---|', count($variants) - 1);
foreach (['TMS', 'TMSd', 'TMSs', 'TMR', 'VMSd', 'VMSdL', 'KS2', 'KS5', 'KS50', 'MD3', 'VMX', 'SPV', 'TPAIRT', 'D24T', 'D24V', 'D24K1'] as $id) {
    foreach (['pre+out', 'pre'] as $flow) {
        $row = "| $id | $flow";
        foreach (array_slice($variants, 1) as $v) {
            $row .= ' | ' . $windowDelta($id, $flow, $v, 60) . ' / ' . $windowDelta($id, $flow, $v, 600) . ' / ' . $windowDelta($id, $flow, $v, 1800) . ' / ' . ($reach($id, $flow, $v) - $reach($id, $flow, 'MAIN'));
        }
        $md[] = $row . ' |';
    }
}
$md[] = '';
$md[] = 'Cell = Δ60 / Δ600 / Δ1800 / Δfull.';
$md[] = '';

$md[] = '## 5. Benign trusted offices and mixed NAT (Login, legit denied; attacker reach)';
$md[] = '';
$md[] = '| Stream | Flow | ' . implode(' | ', $variants) . ' |';
$md[] = '|---|---|' . str_repeat('---|', count($variants));
foreach (['N12T', 'N12M', 'N12', 'TPAIRT', 'TPAIRM'] as $id) {
    foreach (['pre+out', 'pre'] as $flow) {
        $row = "| $id | $flow";
        foreach ($variants as $v) {
            $x = $cell($id, $flow, $v);
            $row .= " | L {$x['legitDenied']}/{$x['legitAttempts']}" . ($x['attackAttempts'] > 0 ? " · A {$x['attackReached']}" : '');
        }
        $md[] = $row . ' |';
    }
}
$md[] = '';

$md[] = '## 6. OTP subset (attacker reach / legit denied)';
$md[] = '';
$md[] = '| Stream | Flow | MAIN | C1 | C3 | D1 |';
$md[] = '|---|---|---|---|---|---|';
foreach (['N12T', 'TMS', 'TMSd', 'VMSd', 'VMSdL', 'KS1', 'TMX', 'D24K1'] as $id) {
    foreach (['pre+out', 'pre'] as $flow) {
        $row = "| $id | $flow";
        foreach (['MAIN', 'C1', 'C3', 'D1'] as $v) {
            $x = $cell($id, $flow, $v, 'otp');
            $row .= ' | ' . ($x['legitAttempts'] > 0 ? "L {$x['legitDenied']}/{$x['legitAttempts']}" : "{$x['attackReached']}/{$x['attackAttempts']} ({$x['attackMaxPerAccount']})");
        }
        $md[] = $row . ' |';
    }
}
$md[] = '';

$md[] = '## 7. Shared NAT offices (one morning, pre+out with retries)';
$md[] = '';
$md[] = 'Cell = browsers locked out all morning **known + unknown** · attacker reach. Unknown = new user or lost Host cookie (same inputs).';
$md[] = '';
$md[] = '| N | Mode | Known % | Attacker | ' . implode(' | ', $variants) . ' |';
$md[] = '|---|---|---|---|' . str_repeat('---|', count($variants));
foreach ([12, 50, 100, 250] as $n) {
    foreach (['LOW', 'MED'] as $mode) {
        foreach ([[0, 'none'], [50, 'none'], [90, 'none'], [100, 'none'], [90, 'NC'], [90, 'KC']] as [$pct, $atk]) {
            $row = "| $n | $mode | $pct | $atk";
            foreach ($variants as $v) {
                $x = $oc["$n|$mode|$pct|$atk|$v"];
                $row .= " | {$x['d']['locked']['known']}+{$x['d']['locked']['unknown']}" . ($atk !== 'none' ? " · {$x['attackReached']}" : '');
            }
            $md[] = $row . ' |';
        }
    }
}
$md[] = '';
$md[] = '### 7.1 Mean delay to a successful login (s, browsers that got in) and browsers with ≥ 1 denial — N = 100, 90 % known, no attacker';
$md[] = '';
$md[] = '| Mode | ' . implode(' | ', $variants) . ' |';
$md[] = '|---|' . str_repeat('---|', count($variants));
foreach (['LOW', 'MED'] as $mode) {
    $row = "| $mode";
    foreach ($variants as $v) {
        $d = $oc["100|$mode|90|none|$v"]['d'];
        $row .= ' | ' . ($d['successes'] > 0 ? intdiv($d['delay'], $d['successes']) : '-') . ' s · ' . ($d['deniedUsers']['known'] + $d['deniedUsers']['unknown']);
    }
    $md[] = $row . ' |';
}
$md[] = '';
$md[] = '### 7.2 Denial causes, N = 100, 90 % known, no attacker (legit denials by enforcing scope)';
$md[] = '';
foreach (['LOW', 'MED'] as $mode) {
    foreach (['MAIN', 'C1', 'C3'] as $v) {
        $c = $oc["100|$mode|90|none|$v"]['causes']['legit'];
        $md[] = "* $mode $v: " . ($c === [] ? 'none' : implode(', ', array_map(static fn(string $k, int $n): string => "`$k` $n", array_keys($c), $c)));
    }
}
$md[] = '';

$md[] = '## 8. Bootstrap (100 browsers, 14 days, fresh store, Host verifies after an admitted successful login)';
$md[] = '';
$md[] = '| Mode | Run | known at start of day 0…13 | locked out day 0…13 | attacker reach day 0…13 |';
$md[] = '|---|---|---|---|---|';
foreach (['LOW', 'MED'] as $mode) {
    foreach (array_keys(AKDR_B_RUNS) as $run) {
        $md[] = "| $mode | $run | " . implode(' ', $bSeries($mode, $run, 'known')) . ' | ' . implode(' ', $bLockedSeries($mode, $run)) . ' | ' . (AKDR_B_RUNS[$run][1] === 'NC' ? implode(' ', $bSeries($mode, $run, 'atk')) : '-') . ' |';
    }
}
$md[] = '';

$md[] = '## 9. Observations';
$md[] = '';
$md[] = '| ID | Kind | Status | Statement | Evidence |';
$md[] = '|---|---|---|---|---|';
foreach ($obs as $x) {
    $md[] = "| {$x['id']} | {$x['kind']} | {$x['status']} | {$x['statement']} | {$x['evidence']} |";
}
$md[] = '';

if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents("$outDir/summary.md", implode("\n", $md));
$jl = '';
foreach ($obs as $x) {
    $jl .= json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}
file_put_contents("$outDir/observations.jsonl", $jl);

$failed = array_filter($obs, static fn(array $x): bool => $x['status'] !== 'REPRODUCED');
if ($failed !== []) {
    fwrite(STDERR, 'NOT REPRODUCED: ' . implode(', ', array_column($failed, 'id')) . "\n");
    exit(6);
}
exit(0);
