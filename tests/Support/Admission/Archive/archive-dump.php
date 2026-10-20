<?php

declare(strict_types=1);

// Archive dump (LT-20, Lead conditions 1-4). Runs in its OWN PHP process, started by the test at run time, with
// PHP_INI_SCAN_DIR= and the archive's memory limit. It `require`s the archived G4 v2 library UNMODIFIED, builds the stream
// rows with the archive's own functions (g4v2FromStream, g4v2Bru) and writes them as JSON. Provisioning events are the archive's
// own closures: each is executed against a real archived Host object and its OBSERVABLE effect (the jar entries and epoch
// bumps it produced) is recorded; the rule that creates events is never re-derived here.
//   archive-dump.php <archive-root> <out-dir>
// Output: <out-dir>/streams.jsonl, <out-dir>/bru.jsonl, <out-dir>/g7streams.jsonl, <out-dir>/fixtures.jsonl, <out-dir>/constants.json,
// <out-dir>/manifest.json (sha-256 and row counts per file).
//
// fixtures.jsonl (LT-23, ruled): the fixture rows of the G5 v2 / G7 fixture scenarios. Where the archive has a LIBRARY function for the
// rows (g5Har, g5OwnerRows, g5Boot, g4v2Bru, g4v2FromStream) it is called; where the rows are built inline in a scenario script, the
// loop is transcribed here with the archive's own row helpers (g4v2Row1, g4v2Issue, g3Attacker, the archive constants) and the
// file:line it transcribes is cited next to it. Two event closures of the scenarios act on Host state (copyJar, passwordChange,
// FORGE's jar copy); dumpEffects() cannot observe them on a fresh Host, so they are recorded as the call the closure makes
// (`copy`, `password`), cited.

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$root = $argv[1] ?? '';
$out = $argv[2] ?? '';
if ($root === '' || $out === '' || ! is_dir($out)) {
    fwrite(STDERR, "usage: archive-dump.php <archive-root> <out-dir>\n");
    exit(2);
}
putenv('G1_ARM=CAND');
putenv('G4_BUILD=G4');
putenv('LAB_ROOT=' . dirname($root, 2));
$_ENV['G1_ARM'] = 'CAND';

require $root . '/g4/integrated_v2/lib/g4v2.php';
// The G5 fixture builders (g5Har, g5OwnerRows, g5Boot): a library file of the archive (no scenario runs when it is loaded).
require $root . '/g5/identity_layer/lib/g5fx.php';

/** @return list<array<string, mixed>> the observable effects of one archived event closure */
function dumpEffects(callable $event): array
{
    $host = new G4V2Host('strict', true, true);
    $event($host, 1_000_000);
    $effects = [];
    foreach ($host->jar as $jarKey => $byAccount) {
        [$holder, $inst] = explode('|', (string) $jarKey, 2);
        foreach ($byAccount as $acct => $t) {
            $effects[] = ['issue' => ['holder' => $holder, 'inst' => $inst, 'acct' => (string) $acct, 'ttl' => $t['ttl'], 'bindOffset' => $t['bindAt'] - $t['iat']]];
        }
    }
    foreach ($host->epoch as $acct => $n) {
        $effects[] = ['revoke' => ['acct' => (string) $acct, 'times' => $n]];
    }

    return $effects;
}

/** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
function dumpRows(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if (isset($r['event'])) {
            $out[] = ['t' => $r['t'], 'effects' => dumpEffects($r['event'])];
            continue;
        }
        $out[] = ['t' => $r['t'], 'kind' => $r['kind'], 'own' => $r['own'], 'who' => $r['who'], 'outcome' => $r['outcome'],
            'inst' => $r['inst'], 'holder' => $r['holder'], 'proof' => $r['proof'], 'ctx' => $r['ctx']->jsonSerialize()];
    }

    return $out;
}

/**
 * The order of g4v2Sort() (g4v2.php:436-441) for rows that are already dumped: time, events first, attackers before legitimate rows.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sortDumped(array $rows): array
{
    usort($rows, static fn(array $a, array $b): int => [$a['t'], isset($a['effects']) ? 0 : 1, ($a['kind'] ?? '') === 'X' ? 0 : 1]
        <=> [$b['t'], isset($b['effects']) ? 0 : 1, ($b['kind'] ?? '') === 'X' ? 0 : 1]);

    return $rows;
}

$hasAttack = static fn(string $id): bool => in_array(g1Streams()[$id]['cls'], ['attack', 'both'], true);
$manifest = [];
$handle = fopen($out . '/streams.jsonl', 'wb');
$count = 0;
foreach (G3_STREAMS as $id) {
    foreach (g1Strategies($id) as $strategy) {
        foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
            $rows = dumpRows(g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none'));
            fwrite($handle, json_encode(['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy, 'rows' => $rows], JSON_THROW_ON_ERROR) . "\n");
            $count++;
        }
    }
}
fclose($handle);
$manifest['streams.jsonl'] = ['lines' => $count];

$handle = fopen($out . '/bru.jsonl', 'wb');
foreach ([false, true] as $hv) {
    fwrite($handle, json_encode(['hv' => $hv, 'rows' => dumpRows(g4v2Bru($hv))], JSON_THROW_ON_ERROR) . "\n");
}
fclose($handle);
$manifest['bru.jsonl'] = ['lines' => 2];

// G7 office and multi-session streams (g7/cross_account_p/scenarios/g7_streams.php:12, 16-21): the archive's own g4v2FromStream.
$handle = fopen($out . '/g7streams.jsonl', 'wb');
$count = 0;
foreach (['N12T', 'TPAIRT', 'TMS', 'TMSd', 'TMX'] as $id) {
    foreach (g1Strategies($id) as $strategy) {
        foreach (array_merge($hasAttack($id) ? ['fresh', 'const'] : ['fresh'], ['none']) as $policy) {
            $rows = dumpRows(g4v2FromStream($id, $strategy, $policy === 'none' ? 'fresh' : $policy, $policy !== 'none'));
            fwrite($handle, json_encode(['stream' => $id, 'cls' => g1Streams()[$id]['cls'], 'strategy' => $strategy, 'ids' => $policy, 'rows' => $rows], JSON_THROW_ON_ERROR) . "\n");
            $count++;
        }
    }
}
fclose($handle);
$manifest['g7streams.jsonl'] = ['lines' => $count];

// ---- fixtures (LT-23) -------------------------------------------------------------------------------------------------------------
$F = 'g5/identity_layer_v2/scenarios/g5v2_fixtures.php';
$fixtures = [];
$fx = static function (string $name, string $source, array $parts) use (&$fixtures): void {
    $fixtures[] = ['fixture' => $name, 'source' => $source] + $parts;
};
// The context helpers of the scenario ($ua, $known): g5v2_fixtures.php:18-19 (repeated at g7_fixtures.php:18-19).
$ua = static fn(int $major): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$major.0.0.0 Safari/537.36";
$known = static fn(string $ip, string $acct, string $agent): RateLimitContextDTO => new RateLimitContextDTO($ip, $agent, $acct, null, null, false, [], true, null);

// HAR-B / HAR-N: g5v2_fixtures.php:31-37 -> g5Har() (g5/identity_layer/lib/g5fx.php:13-26).
foreach (['HAR-B' => true, 'HAR-N' => false] as $name => $bound) {
    $fx($name, "$F:31-37; g5fx.php:13-26", ['rows' => dumpRows(g5Har($bound))]);
}
// REC: g5v2_fixtures.php:46-61. The BRU rows to 1,800 s (g4v2Bru(false, 1800)) and the owner row; the owner retries every 60 s from
// 1,785 + delta (the test runs the loop and sets t).
$recOwner = new RateLimitContextDTO('203.0.113.200', FIREFOX_130, 'acct-v', null, null, false, [], false, null);
$fx('REC', "$F:46-61", ['rows' => dumpRows(g4v2Bru(false, 1800)),
    'owner' => dumpRows([g4v2Row1(0, $recOwner, 'O', 'owner', 'ok', 'i:rec-owner', 'h:rec-owner')])[0]]);
// CROSS: g5v2_fixtures.php:62-74.
$rows = [g4v2Issue(0, 'atk', 'acct-atk', 'x:atk')];
for ($i = 0; $i < 200; $i++) {
    $c = new RateLimitContextDTO('203.0.113.90', CHROME, 'acct-cv' . $i, null, null, false, [], false, null);
    $rows[] = g4v2Row1(3 * $i, $c, 'X', 'atk', 'fail', 'x:atk', 'atk');
}
$fx('CROSS', "$F:62-74", ['rows' => dumpRows($rows)]);
// CLEAR: g5v2_fixtures.php:75-85.
$rows = [];
for ($d = 0; $d < 5; $d++) {
    foreach ([32400, 46800, 61200] as $h) {
        $c = new RateLimitContextDTO('198.51.100.77', CHROME, 'acct-c', null, null, false, [], false, null);
        $rows[] = g4v2Row1($d * 86400 + $h, $c, 'L', 'c', 'ok', 'i:c' . $d, 'h:c' . $d);
    }
}
$fx('CLEAR', "$F:75-85", ['rows' => dumpRows($rows)]);
// E2 pop / ctl: g5v2_fixtures.php:86-103 -> g5OwnerRows() (g5fx.php:58-76).
foreach (['pop' => true, 'ctl' => false] as $k => $pop) {
    $oc = new RateLimitContextDTO('198.51.100.150', FIREFOX_130, 'acct-e2', null, null, false, [], true, null);
    $rows = array_merge([g4v2Issue(0, 'h:e2', 'acct-e2', 'i:e2', 600)], g5OwnerRows($oc, 'e2', 'i:e2', 'h:e2'));
    if ($pop) {
        for ($t = 0, $n = 0; $t < 2400; $t += 15, $n++) {
            $rows[] = g4v2Row1($t, g3Attacker($n % 20, 'acct-e2'), 'X', 'pop', 'fail', 'x:p' . ($n % 20), 'pop');
        }
    }
    $fx('E2:' . $k, "$F:86-103; g5fx.php:58-76", ['rows' => dumpRows(g4v2Sort($rows))]);
}
// K5U fail / ctl: g5v2_fixtures.php:104-117 (rows not sorted, as there).
foreach (['fail' => true, 'ctl' => false] as $k => $uFails) {
    $oc = new RateLimitContextDTO('198.51.100.151', FIREFOX_130, 'acct-k5', null, null, false, [], true, null);
    $rows = array_merge([g4v2Issue(0, 'h:k5', 'acct-k5', 'i:k5', G4_TOKEN_TTL, 600 - G4V2_MAX_LIFE)], g5OwnerRows($oc, 'k5', 'i:k5', 'h:k5', $uFails));
    $fx('K5U:' . $k, "$F:104-117; g5fx.php:58-76", ['rows' => dumpRows($rows)]);
}
// BOOT / BOOT-X: g5v2_fixtures.php:118-151 -> g5Boot() (g5fx.php:99-125; mt_srand(4242) in this process).
foreach (['BOOT' => false, 'BOOT-X' => true] as $name => $spray) {
    $fx($name, "$F:118-151; g5fx.php:99-125", ['rows' => dumpRows(g5Boot($spray))]);
}
// UAX-NP: g5v2_fixtures.php:153-161.
$rows = [g4v2Issue(0, 'h:np', 'acct-np', 'i:np')];
for ($i = 0; $i < 12; $i++) {
    $rows[] = g4v2Row1(3600 * $i + 60, $known('198.51.100.60', 'acct-np', $ua(120 + $i)), 'O', 'np', 'ok', 'i:np', 'h:np');
}
$fx('UAX-NP', "$F:153-161", ['rows' => dumpRows(g4v2Sort($rows))]);
// FORGE: g5v2_fixtures.php:163-179. The per-attempt event copies the owner's jar to the thief's fresh id (the call the closure makes).
$rows = [g4v2Issue(0, 'owner', 'acct-fg', 'i:fg')];
$copies = [];
for ($t = 600, $n = 0; $t < 43200; $t += 15, $n++) {
    $inst = 'x:fg' . $n;
    $copies[] = ['t' => $t - 1, 'effects' => [['copy' => ['from' => 'owner|i:fg', 'to' => 'thief|' . $inst]]]];
    $rows[] = g4v2Row1($t, g3Attacker($n, 'acct-fg'), 'X', 'thief', 'fail', $inst, 'thief');
}
$dumped = dumpRows($rows);
$fx('FORGE', "$F:163-179", ['rows' => sortDumped(array_merge($dumped, $copies))]);
// MINT: g5v2_fixtures.php:181-188.
$rows = [];
for ($t = 0, $n = 0; $t < 86400; $t += 60, $n++) {
    $rows[] = g4v2Row1($t, $known('198.51.100.61', 'acct-mint', CHROME), 'X', 'm', 'fail', 'm:' . $n, 'm');
}
$fx('MINT', "$F:181-188", ['rows' => dumpRows($rows)]);
// LOST / TOKEN: g5v2_fixtures.php:190-203.
foreach (['LOST' => false, 'TOKEN' => true] as $name => $token) {
    foreach ([600, 300, 60] as $iv) {
        $rows = $token ? [g4v2Issue(0, 'h:kd', 'acct-kd', 'i:kd')] : [];
        for ($t = 0; $t < 86400; $t += $iv) {
            $rows[] = g4v2Row1($t, $known('198.51.100.20', 'acct-kd', CHROME), 'O', 'kd', 'fail', 'i:kd', 'h:kd');
        }
        $fx($name . ':' . $iv, "$F:190-203", ['rows' => dumpRows(g4v2Sort($rows))]);
    }
}
// UAX-ASM flip / ctl: g5v2_fixtures.php:205-216.
foreach (['flip' => true, 'ctl' => false] as $k => $flip) {
    $rows = [g4v2Issue(0, 'h:ua', 'acct-ua', 'i:ua')];
    for ($i = 0; $i < 12; $i++) {
        $rows[] = g4v2Row1($i * 60, $known('198.51.100.62', 'acct-ua', $flip && $i >= 6 ? $ua(127) : $ua(126)), 'O', 'ua', 'fail', 'i:ua', 'h:ua');
    }
    $fx('UAX-ASM:' . $k, "$F:205-216", ['rows' => dumpRows(g4v2Sort($rows))]);
}
// MIXPRES-ASM both / ctl: g5v2_fixtures.php:218-243 (B sends no id; the archived k5Key values are study-key hashes and are not compared).
foreach (['both' => true, 'ctl' => false] as $k => $withB) {
    $rows = [];
    if ($withB) {
        foreach ([0, 30, 60, 90] as $t) {
            $rows[] = g4v2Row1($t, $known('198.51.100.63', 'acct-mx', CHROME), 'O', 'b', 'fail', null, 'h:b');
        }
    }
    foreach ([120, 150] as $t) {
        $rows[] = g4v2Row1($t, $known('198.51.100.63', 'acct-mx', CHROME), 'O', 'a', 'fail', 'i:a', 'h:a');
    }
    $fx('MIXPRES-ASM:' . $k, "$F:218-243", ['rows' => dumpRows(g4v2Sort($rows))]);
}
// STEAL (thief side, password-change variant): g7/cross_account_p/lib/g7.php:165-224 (= g5fx.php:28-51, 127-158). The pre part is
// the sorted merge of lines 172-176; the copyJar event at 600 s and the password change at 43,200 s are recorded as their calls.
$acct = 'acct-st';
$ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, $acct, null, null, false, [], false, null);
$pre = g4v2Sort(array_merge(
    [g4v2Issue(0, 'owner', $acct, 'i:st')],
    array_map(static fn(int $t): array => g4v2Row1($t, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), range(60, 43199, 7200)),
    array_map(static fn(int $t): array => g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief'), range(615, 43199, 15)),
));
$after = [];
for ($t = 43215; $t < 86400; $t += 15) {
    $after[] = g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief');
}
$fx('STEAL', 'g7/cross_account_p/lib/g7.php:165-224', [
    'pre' => sortDumped(array_merge(dumpRows($pre), [['t' => 600, 'effects' => [['copy' => ['from' => 'owner|i:st', 'to' => 'thief|i:st']]]]])),
    'change' => ['t' => 43200, 'effects' => [['password' => ['holder' => 'owner', 'acct' => $acct, 'inst' => 'i:st']]]],
    'after' => dumpRows($after),
    'owner' => dumpRows([g4v2Row1(0, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner')])[0],
]);
$handle = fopen($out . '/fixtures.jsonl', 'wb');
foreach ($fixtures as $f) {
    fwrite($handle, json_encode($f, JSON_THROW_ON_ERROR) . "\n");
}
fclose($handle);
$manifest['fixtures.jsonl'] = ['lines' => count($fixtures)];

$manifest['constants.json'] = ['lines' => 1];
file_put_contents($out . '/constants.json', json_encode(['tokenTtl' => G4_TOKEN_TTL, 'taint' => G4_TAINT, 'maxLife' => G4V2_MAX_LIFE], JSON_THROW_ON_ERROR));

foreach ($manifest as $file => $info) {
    $manifest[$file]['sha256'] = hash_file('sha256', $out . '/' . $file);
}
file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
