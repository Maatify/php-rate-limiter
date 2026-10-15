<?php

declare(strict_types=1);

// Negative-control generator for run.sh: writes a mutated copy of the H01 / H02 evidence so that report.php can be shown to reject it.
//
//   php selftest.php <mutation> <h01.jsonl> <h02.jsonl> <outDir>    → prints "<h01 path> <h02 path> <expected exit code>"
//
// Expected exit 5 = report must reject the evidence; 6 = evidence valid, a descriptive observation must turn NOT_REPRODUCED.

[, $mutation, $h01In, $h02In, $outDir] = $argv + [null, null, null, null, null];
if ($mutation === null || $outDir === null) {
    fwrite(STDERR, "usage: selftest.php <mutation> <h01> <h02> <outDir>\n");
    exit(2);
}

/** @return list<array<string, mixed>> */
function load(string $path): array
{
    return array_map(static fn(string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
}

/** @param list<array<string, mixed>> $rows */
function dump(array $rows): string
{
    return implode('', array_map(static fn(array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $rows));
}

/** @param list<array<string, mixed>> $rows */
function find(array $rows, callable $match): int
{
    foreach ($rows as $i => $r) {
        if ($match($r)) {
            return $i;
        }
    }
    throw new RuntimeException('selftest: target row not found');
}

$h01 = load($h01In);
$h02 = load($h02In);
$cell = static fn(string $s, string $m): callable => static fn(array $r): bool => ($r['stream'] ?? null) === $s && ($r['mode'] ?? null) === $m;
$dayRow = static fn(string $v, int $d): callable => static fn(array $r): bool => ($r['variant'] ?? null) === $v && ($r['day'] ?? null) === $d;
$raw01 = null;
$raw02 = null;
$expect = 5;

switch ($mutation) {
    case 'empty':
        $raw01 = '';
        break;
    case 'malformed':
        $raw01 = substr(dump($h01), 0, 200) . "{x\n";
        break;
    case 'missing_cell':
        array_splice($h01, find($h01, $cell('OFF100', 'M1')), 1);
        break;
    case 'duplicate_cell':
        $h01[] = $h01[find($h01, $cell('SPR_S', 'M0'))];
        break;
    case 'extra_field':
        $h01[find($h01, $cell('BRU_S', 'M2'))]['note'] = 'x';
        break;
    case 'patched_label':
        $h01[0]['patched'] = true;
        break;
    case 'wrong_hash':
        $h01[0]['sha256']['EvaluationPipeline'] = str_repeat('0', 64);
        break;
    case 'bad_length':
        $i = find($h01, $cell('UAS', 'M0'));
        $h01[$i]['check'] .= 'A';
        break;
    case 'bad_alphabet':
        $i = find($h01, $cell('SPR_R', 'M1'));
        $h01[$i]['check'][5] = 'X';
        break;
    case 'bad_transition':
        // A record step after a denied check (pre+out never records then).
        $i = find($h01, $cell('SPR_S', 'M0'));
        $k = strpos($h01[$i]['check'], 'H');
        $h01[$i]['record'][$k] = 'A';
        break;
    case 'corrupt_trace':
        // Length, alphabet and transitions stay valid; only the stored reach no longer matches the trace.
        $i = find($h01, $cell('SPR_S', 'M0'));
        $k = strpos($h01[$i]['check'], 'H');
        $h01[$i]['check'][$k] = 'A';
        $h01[$i]['record'][$k] = 'S';
        break;
    case 'mismatched_reach':
        $h01[find($h01, $cell('BRU_R', 'M4'))]['reach']++;
        break;
    case 'mismatched_users':
        $h01[find($h01, $cell('OFF12', 'M1'))]['usersDenied']--;
        break;
    case 'wrong_conf':
        $h01[find($h01, $cell('SPR_S', 'M3'))]['conf'] = 'LOW';
        break;
    case 'masked_rotation':
        // A rotation regression versus the same-mode stable id, with a fully consistent trace: must be caught by O9 (exit 6).
        $i = find($h01, $cell('SPR_R', 'M1'));
        $h01[$i]['check'] = str_repeat('A', 10) . substr($h01[$i]['check'], 10);
        $h01[$i]['record'] = str_repeat('S', 10) . substr($h01[$i]['record'], 10);
        $h01[$i]['reach'] = substr_count($h01[$i]['check'], 'A');
        $expect = 6;
        break;
    case 'h02_missing_row':
        array_splice($h02, find($h02, $dayRow('CAP5', 7)), 1);
        break;
    case 'h02_duplicate_row':
        $h02[] = $h02[find($h02, $dayRow('BASE', 3))];
        break;
    case 'h02_broken_continuity':
        $i = find($h02, $dayRow('BASE', 5));
        $h02[$i]['establishedAtStart']++;
        $h02[$i]['establishedAtEnd']++;
        $h02[$i]['newcomers']--;
        $h02[$i]['newcomersNoLoginToday']--;
        break;
    case 'h02_reach_on_non_attack':
        $h02[find($h02, $dayRow('FIX7', 2))]['attackerReach'] = 0;
        break;
    case 'h02_masked_attack_gain':
        // ATK reach equal to the MAIN control on every day: B5 must turn NOT_REPRODUCED (exit 6).
        foreach ($h02 as $i => $r) {
            if (($r['variant'] ?? null) === 'ATK') {
                $h02[$i]['attackerReach'] = 13;
            }
        }
        $expect = 6;
        break;
    default:
        fwrite(STDERR, "selftest: unknown mutation $mutation\n");
        exit(2);
}

@mkdir($outDir, 0777, true);
$p01 = "$outDir/$mutation.h01.jsonl";
$p02 = "$outDir/$mutation.h02.jsonl";
file_put_contents($p01, $raw01 ?? dump($h01));
file_put_contents($p02, $raw02 ?? dump($h02));
echo "$p01 $p02 $expect\n";
