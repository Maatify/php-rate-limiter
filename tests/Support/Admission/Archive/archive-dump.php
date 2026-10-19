<?php

declare(strict_types=1);

// Archive dump (LT-20, Lead conditions 1-4). Runs in its OWN PHP process, started by the test at run time, with
// PHP_INI_SCAN_DIR= and the archive's memory limit. It `require`s the archived G4 v2 library UNMODIFIED, builds the stream
// rows with the archive's own functions (g4v2FromStream, g4v2Bru) and writes them as JSON. Provisioning events are the archive's
// own closures: each is executed against a real archived Host object and its OBSERVABLE effect (the jar entries and epoch
// bumps it produced) is recorded; the rule that creates events is never re-derived here.
//   archive-dump.php <archive-root> <out-dir>
// Output: <out-dir>/streams.jsonl, <out-dir>/bru.jsonl, <out-dir>/manifest.json (sha-256 and row counts per file).

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

$manifest['constants.json'] = ['lines' => 1];
file_put_contents($out . '/constants.json', json_encode(['tokenTtl' => G4_TOKEN_TTL, 'taint' => G4_TAINT, 'maxLife' => G4V2_MAX_LIFE], JSON_THROW_ON_ERROR));

foreach ($manifest as $file => $info) {
    $manifest[$file]['sha256'] = hash_file('sha256', $out . '/' . $file);
}
file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
