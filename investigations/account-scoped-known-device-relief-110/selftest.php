<?php

declare(strict_types=1);

// Negative-control mutation generator for report.php (used by run.sh).
//
//   php selftest.php <mutation> <v01.jsonl> <o01.jsonl> <b01.jsonl> <dir>
//
// Writes the (possibly mutated) three evidence files into <dir> and prints "<v01> <o01> <b01> <expected exit code>". Exit-5 mutations break
// the evidence (report.php must reject them); exit-6 mutations are internally consistent evidence in which an observation is masked
// (report.php must accept the evidence and report NOT_REPRODUCED).
require __DIR__ . '/lib/akdr.php';

[$_, $m, $vIn, $oIn, $bIn, $dir] = $argv;
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$read = static fn(string $p): array => array_map(static fn(string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), file($p, FILE_IGNORE_NEW_LINES));
$V = $read($vIn);
$O = $read($oIn);
$B = $read($bIn);
$raw = ['v' => null, 'o' => null, 'b' => null];
$find = static function (array $rows, callable $f): int {
    foreach ($rows as $i => $r) {
        if ($f($r)) {
            return $i;
        }
    }
    throw new \RuntimeException('selftest: row not found');
};
$vCell = static fn(string $id, string $flow, string $v, string $policy = 'login'): int => $find($V, static fn(array $r): bool => $r['row'] === 'cell' && $r['stream'] === $id && $r['flow'] === $flow && $r['variant'] === $v && $r['policy'] === $policy);
$oCell = static fn(int $n, string $mode, int $pct, string $atk, string $v): int => $find($O, static fn(array $r): bool => $r['row'] === 'cell' && $r['size'] === $n && $r['mode'] === $mode && $r['knownPct'] === $pct && $r['attacker'] === $atk && $r['variant'] === $v);
$bDay = static fn(string $mode, string $run, int $day): int => $find($B, static fn(array $r): bool => $r['row'] === 'day' && $r['mode'] === $mode && $r['run'] === $run && $r['day'] === $day);

$want = 5;
switch ($m) {
    case 'empty':
        $raw['v'] = '';
        break;
    case 'malformed':
        $raw['v'] = implode("\n", array_map(static fn(array $r): string => json_encode($r), $V)) . "\n{\"s\":\"V01\",";
        break;
    case 'missing_cell':
        unset($V[$vCell('TMSd', 'pre+out', 'C1')]);
        break;
    case 'duplicate_cell':
        $V[] = $V[$vCell('TMSd', 'pre+out', 'C1')];
        break;
    case 'extra_field':
        $V[$vCell('TMS', 'pre', 'D1')]['note'] = 'x';
        break;
    case 'wrong_hash':
        $V[0]['sha256']['EvaluationPipeline'] = str_repeat('0', 64);
        break;
    case 'bad_length':
        $i = $vCell('TMSd', 'pre', 'C2');
        $V[$i]['check'] = substr($V[$i]['check'], 1);
        break;
    case 'bad_alphabet':
        $i = $vCell('TMSd', 'pre', 'C2');
        $V[$i]['check'][5] = 'X';
        break;
    case 'bad_transition':
        // a denied pre+out check followed by a recorded outcome
        $i = $vCell('TMSd', 'pre+out', 'MAIN');
        $j = strpos($V[$i]['check'], 'H');
        $V[$i]['record'][$j] = 'A';
        break;
    case 'relief_on_main':
        $i = $vCell('TMSd', 'pre+out', 'MAIN');
        $V[$i]['relieved'][0] = 'R';
        $V[$i]['attackRelieved']++;
        break;
    case 'relief_on_unknown':
        $i = $vCell('SP1n', 'pre+out', 'C1');
        $V[$i]['relieved'][0] = 'R';
        $V[$i]['attackRelieved']++;
        break;
    case 'mismatched_reach':
        $V[$vCell('VMSd', 'pre+out', 'D1')]['attackReached']++;
        break;
    case 'source_sum':
        $i = $vCell('TMSd', 'pre+out', 'C1');
        $V[$i]['attackDeniedBySource']['tampered'] = 1;
        break;
    case 'gate_false':
        $i = $find($V, static fn(array $r): bool => $r['row'] === 'gate' && $r['gate'] === 'G-SUPP');
        $V[$i]['ok'] = false;
        break;
    case 'o_known_mismatch':
        $i = $oCell(100, 'LOW', 90, 'none', 'C1');
        $O[$i]['known'][0] = $O[$i]['known'][0] === '1' ? '0' : '1';
        break;
    case 'o_counter_shift':
        $O[$oCell(100, 'LOW', 90, 'KC', 'C1')]['attackReached']--;
        break;
    case 'o_missing_cell':
        unset($O[$oCell(250, 'MED', 90, 'NC', 'D2')]);
        break;
    case 'o_token_after_success':
        $i = $oCell(12, 'LOW', 100, 'none', 'C1');
        preg_match('/(?:^| )(\d+)@(\d+)o(\d)AA([R-])/', $O[$i]['tokens'], $mm);
        $O[$i]['tokens'] .= ' ' . $mm[1] . '@' . ((int) $mm[2] + 99) . 'o' . $mm[3] . 'AA' . $mm[4];
        break;
    case 'o_relief_on_unknown':
        $i = $oCell(100, 'LOW', 90, 'none', 'C1');
        $k = strpos($O[$i]['known'], '0');
        $O[$i]['tokens'] = preg_replace('/(^| )(' . $k . '@\d+[of]\d[ASH][ASH-])-/', '$1$2R', $O[$i]['tokens'], 1);
        $O[$i]['relievedRequests']++;
        break;
    case 'b_carry_broken':
        $i = $bDay('LOW', 'MAIN', 3);
        $B[$i]['startKnown'][$k = strpos($B[$i]['startKnown'], '0')] = '1';
        break;
    case 'b_missing_day':
        unset($B[$bDay('MED', 'C3+NC', 7)]);
        break;
    case 'b_dropped_retry':
        // drop the last retry of a browser that was locked out: it then stops before its 3 retries
        $i = $bDay('LOW', 'MAIN', 0);
        $tokens = explode(' ', $B[$i]['tokens']);
        foreach ($tokens as $t => $tk) {
            if (preg_match('/^\d+@\d+[of]3[SH]-/', $tk)) {
                unset($tokens[$t]);
                break;
            }
        }
        $B[$i]['tokens'] = implode(' ', $tokens);
        break;
    case 'masked_gain':
        // TMSd C1 pre+out replaced by MAIN's decisions, internally consistent (every known request marked relieved): R3 must fail.
        $want = 6;
        $c = $vCell('TMSd', 'pre+out', 'C1');
        $mi = $vCell('TMSd', 'pre+out', 'MAIN');
        foreach (['legitAttempts', 'legitDenied', 'legitUsersDenied', 'attackAttempts', 'attackReached', 'attackDenied', 'attackAccountsReached', 'attackMaxPerAccount', 'attackReachedFirst600', 'legitDeniedBySource', 'attackDeniedBySource', 'blockIssuances', 'check', 'record'] as $f) {
            $V[$c][$f] = $V[$mi][$f];
        }
        $V[$c]['relieved'] = str_repeat('R', strlen($V[$mi]['check']));
        $V[$c]['attackRelieved'] = strlen($V[$mi]['check']);
        break;
    case 'masked_pool':
        // O01 N100 LOW 90 % NC under C3 replaced by MAIN's tokens with relief marked on the known browsers: R11 must fail.
        $want = 6;
        $c = $oCell(100, 'LOW', 90, 'NC', 'C3');
        $mi = $oCell(100, 'LOW', 90, 'NC', 'MAIN');
        $known = $O[$c]['known'];
        $rel = 0;
        $O[$c]['tokens'] = implode(' ', array_map(static function (string $tk) use ($known, &$rel): string {
            if (preg_match('/^(\d+)@/', $tk, $mm) && $known[(int) $mm[1]] === '1') {
                $rel++;

                return substr($tk, 0, -1) . 'R';
            }

            return $tk;
        }, explode(' ', $O[$mi]['tokens'])));
        foreach (['attackReached', 'attackAttempts', 'attackAccounts', 'attackMaxPerAccount', 'causes'] as $f) {
            $O[$c][$f] = $O[$mi][$f];
        }
        $O[$c]['relievedRequests'] = $rel;
        break;
    default:
        fwrite(STDERR, "unknown mutation $m\n");
        exit(2);
}
$out = [];
foreach (['v' => $V, 'o' => $O, 'b' => $B] as $k => $rows) {
    $path = "$dir/$k.jsonl";
    file_put_contents($path, $raw[$k] ?? implode('', array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", array_values($rows))));
    $out[] = $path;
}
echo implode(' ', $out), " $want\n";
