<?php

declare(strict_types=1);

// Negative-control generator for report.php (used by run.sh). Writes a mutated copy of the evidence and prints
// "<c01> <c02> <c03> <expected exit code>". Exit 5 = report.php must reject the evidence as invalid; exit 6 = the evidence stays
// internally consistent (metrics re-derived) but a pinned observation must turn NOT REPRODUCED (masked security / fairness result).
//
//   php selftest.php <mutation> <c01.jsonl> <c02.jsonl> <c03.jsonl> <dir>
require_once __DIR__ . '/lib/ci97.php';

[, $mutation, $p01, $p02, $p03, $dir] = $argv;
@mkdir($dir, 0777, true);
$read = static fn(string $p): array => array_map(static fn(string $l): array => json_decode($l, true, 64, JSON_THROW_ON_ERROR), file($p, FILE_IGNORE_NEW_LINES));
$rows = ['c01' => $read($p01), 'c02' => $read($p02), 'c03' => $read($p03)];
$find = static function (array $rs, array $where): int {
    foreach ($rs as $i => $r) {
        if (array_intersect_assoc($where, $r) === $where) {
            return $i;
        }
    }
    throw new \RuntimeException('selftest target not found: ' . json_encode($where));
};
$remetric = static function (array $r): array {
    foreach (ci97Metrics($r['check'], $r['record'], $r['kinds'], $r['who'], $r['flow']) as $k => $v) {
        $r[$k] = $v;
    }

    return $r;
};
$raw = null;
$want = 5;
switch ($mutation) {
    case 'empty':
        $raw = ['c01' => ''];
        break;
    case 'malformed':
        $raw = ['c01' => implode("\n", array_map('json_encode', $rows['c01'])) . "\n{not json\n"];
        break;
    case 'missing_cell':
        array_splice($rows['c01'], 5, 1);
        break;
    case 'duplicate_cell':
        $rows['c01'][] = $rows['c01'][5];
        break;
    case 'extra_field':
        $rows['c01'][5]['note'] = 'x';
        break;
    case 'wrong_hash':
        $rows['c01'][0]['sha256']['EvaluationPipeline'] = str_repeat('0', 64);
        break;
    case 'patched_flag':
        $rows['c03'][0]['srcPatched'] = true;
        break;
    case 'bad_alphabet':
        $i = $find($rows['c01'], ['stream' => 'X2', 'arm' => 'CIRI', 'strategy' => 'none', 'flow' => 'pre']);
        $rows['c01'][$i]['check'][0] = 'X';
        break;
    case 'bad_transition':
        $i = $find($rows['c01'], ['stream' => 'X2', 'arm' => 'CIRI', 'strategy' => 'none', 'flow' => 'pre']);
        $rows['c01'][$i]['record'][0] = 'A';
        break;
    case 'metric_mismatch':
        $i = $find($rows['c01'], ['stream' => 'L1', 'arm' => 'CIRI', 'flow' => 'pre+out']);
        $rows['c01'][$i]['legitDenied']--;
        break;
    case 'kinds_tamper':
        $i = $find($rows['c01'], ['stream' => 'X2L', 'arm' => 'CIRI', 'strategy' => 'none', 'flow' => 'pre+out']);
        $rows['c01'][$i]['kinds'] = str_replace('L', 'X', $rows['c01'][$i]['kinds']);
        $rows['c01'][$i] = $remetric($rows['c01'][$i]);
        break;
    case 'missing_ctl':
        $i = $find($rows['c01'], ['stream' => 'D1', 'arm' => 'S0N', 'flow' => 'pre+out']);
        unset($rows['c01'][$i]['ctlCheck'], $rows['c01'][$i]['ctlRecord']);
        break;
    case 'attribution_gap':
        $i = $find($rows['c01'], ['stream' => 'D1', 'arm' => 'S0N', 'flow' => 'pre+out']);
        $rows['c01'][$i]['firstDenialBlockedKeys'] = ['K2' => 1];
        break;
    case 'c02_missing_row':
        array_splice($rows['c02'], 3, 1);
        break;
    case 'c02_bad_workers':
        $i = $find($rows['c02'], ['row' => 'mix', 'stream' => 'KD']);
        $rows['c02'][$i]['workers'] = substr($rows['c02'][$i]['workers'], 1);
        break;
    case 'posthoc_unlabelled':
        $rows['c03'][3]['posthoc'] = false;
        break;
    case 'masked_evasion':
        // CIRI X7 static pre+out made to look like S0N's best response (44 allowed) — internally consistent.
        $want = 6;
        $i = $find($rows['c01'], ['stream' => 'X7', 'arm' => 'CIRI', 'strategy' => 'static', 'flow' => 'pre+out']);
        $r = $rows['c01'][$i];
        for ($k = 44, $n = strlen($r['check']); $k < $n; $k++) {
            $r['check'][$k] = 'H';
            $r['record'][$k] = '-';
        }
        $rows['c01'][$i] = $remetric($r);
        break;
    case 'masked_nat_regression':
        $want = 6;
        $i = $find($rows['c01'], ['stream' => 'L1', 'arm' => 'CIRI', 'flow' => 'pre+out']);
        $r = $rows['c01'][$i];
        $r['check'] = str_repeat('A', strlen($r['check']));
        $r['record'] = str_repeat('A', strlen($r['record']));
        $r['firstDenialBlockedKeys'] = [];
        $rows['c01'][$i] = $remetric($r);
        break;
    case 'baseline_drift':
        $want = 6;
        $i = $find($rows['c01'], ['stream' => 'D1', 'arm' => 'S0N', 'flow' => 'pre+out']);
        $r = $rows['c01'][$i];
        $k = strrpos($r['check'], 'H');
        $r['check'][$k] = 'A';
        $r['record'][$k] = 'A';
        $r['firstDenialBlockedKeys']['K2']--;
        $rows['c01'][$i] = $remetric($r);
        break;
    case 'composition_mismatch':
        $want = 6;
        $i = $find($rows['c02'], ['row' => 'bld', 'stream' => 'D1', 'arm' => 'CIRI']);
        $r = $rows['c02'][$i];
        $r['check'][10] = 'S';
        $r['record'][10] = '-';
        $rows['c02'][$i] = $remetric($r);
        break;
    case 'first_denial_index':
        $i = $find($rows['c01'], ['stream' => 'KD', 'arm' => 'CIRI', 'flow' => 'pre+out']);
        $rows['c01'][$i]['ownerFirstDenial']['index']++;
        break;
    case 'masked_k5_attribution':
        // The runtime witness is moved to the account watcher (a concurrent account-scoped SOFT) — consistent with every string.
        $want = 6;
        $i = $find($rows['c01'], ['stream' => 'KD', 'arm' => 'CIRI', 'flow' => 'pre+out']);
        $rows['c01'][$i]['ownerFirstDenial']['accountWatcherDelta'] = 1;
        $rows['c01'][$i]['ownerFirstDenial']['deviceWatcherDelta'] = 0;
        break;
    case 'ablation_still_denied':
        $want = 6;
        $i = $find($rows['c01'], ['stream' => 'UAC', 'arm' => 'S0I', 'flow' => 'out']);
        $k = $rows['c01'][$i]['ownerFirstDenial']['index'];
        $rows['c01'][$i]['k5AblatedRecord'][$k] = 'S';
        break;
    default:
        fwrite(STDERR, "unknown mutation $mutation\n");
        exit(2);
}
$out = [];
foreach (['c01', 'c02', 'c03'] as $sc) {
    $out[$sc] = "$dir/$mutation.$sc.jsonl";
    $content = $raw[$sc] ?? implode('', array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", $rows[$sc]));
    file_put_contents($out[$sc], $content);
}
echo "{$out['c01']} {$out['c02']} {$out['c03']} $want\n";
