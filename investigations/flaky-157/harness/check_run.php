<?php

declare(strict_types=1);

// #157 per-run verdict (PLAN.md §5.2, §5.3, §9.2). Prints one JSON line.
//
// Usage: php check_run.php --arm=<l|d0|d1|d2|lconfig> --id=<n> --dir=<run dir> --kref=<kref.json>
//          --exit=<phpunit status> --redis-port=<p> [--case=<0|1|2> for D]

namespace Flaky157;

require __DIR__ . '/lib.php';

$options = getopt('', ['arm:', 'id:', 'dir:', 'kref:', 'exit:', 'redis-port:', 'case:']);
$arm = (string) $options['arm'];
$dir = (string) $options['dir'];
$isL = $arm === 'l' || $arm === 'lconfig';
$deleteCase = isset($options['case']) && $options['case'] !== '' ? (int) $options['case'] : null;
$verdict = [
    'arm' => $arm,
    'id' => (int) $options['id'],
    'exit' => (int) $options['exit'],
    'redis_port' => (int) $options['redis-port'],
    'v_port' => (int) $options['redis-port'] !== 6379,
];
$problems = [];
$findings = [];

// --- junit, target outcome, V-ORD -------------------------------------------
$junitPath = $dir . '/junit.xml';
$cases = is_file($junitPath) ? junitCases($junitPath) : [];
$ordinal = targetOrdinal($cases);
$target = $ordinal === null ? null : $cases[$ordinal - 1];
$verdict['tests'] = count($cases);
$verdict['ordinal'] = $ordinal;
if ($isL) {
    $kref = json_decode((string) file_get_contents((string) $options['kref']), true, 8, JSON_THROW_ON_ERROR);
    $verdict['k_ref'] = $kref['k_ref'];
    $verdict['v_ord'] = $ordinal === $kref['k_ref'];
    $sequence = array_map(static fn(array $case): string => $case['class'] . '::' . $case['name'], array_slice($cases, 0, (int) $ordinal));
    $verdict['sequence_equal'] = $ordinal !== null && hash('sha256', implode("\n", $sequence)) === $kref['sequence_sha256'];
}
$failureText = $target['failure'] ?? null;
$verdict['target'] = $target === null ? 'missing' : ($failureText === null ? 'pass' : 'fail');
if ($failureText !== null) {
    preg_match_all('/RedisFullCapabilityStoreIntegrationTest\.php:(\d+)/', $failureText, $lines);
    $verdict['target_failure'] = [
        'first_line' => strtok(substr($failureText, strpos($failureText, ': ') + 2), "\n"),
        'accepted_message' => str_contains($failureText, FAILURE_MESSAGE),
        'lines' => array_map('intval', $lines[1]),
    ];
}
$verdict['other_failures'] = count(array_filter($cases, static fn(array $case): bool => $case['failure'] !== null)) - ($failureText === null ? 0 : 1);

// --- tap log, V-FRAME -------------------------------------------------------
$rows = readJsonl($dir . '/tap.jsonl');
$summary = null;
$records = [];
foreach ($rows as $row) {
    if ($row['t'] === 'summary') {
        $summary = $row;
    } elseif ($row['t'] === 'cmd') {
        $records[] = $row;
    } elseif ($row['t'] === 'parse_error') {
        $problems[] = 'parse_error:' . $row['side'];
    }
}
usort($records, static fn(array $a, array $b): int => $a['seq'] <=> $b['seq']);
if ($summary === null) {
    $problems[] = 'no_tap_summary';
} else {
    foreach (['unmatched_commands', 'unmatched_replies', 'parse_errors'] as $counter) {
        if ($summary[$counter] !== 0) {
            $problems[] = $counter . '=' . $summary[$counter];
        }
    }
    $verdict['tap'] = array_intersect_key($summary, array_flip(['connections', 'commands', 'replies', 'records', 'probes', 'open_at_stop']));
}

$perCase = [0 => [], 1 => [], 2 => []];
foreach ($records as $record) {
    if ($record['key'] !== null) {
        $perCase[$record['key']][] = $record;
        if (! empty($record['reply_missing'])) {
            $problems[] = 'reply_missing:' . $record['kind'] . ':' . $record['key'];
        }
    }
}
$isError = static fn(array $record): bool => is_array($record['reply'] ?? null) && isset($record['reply']['error']);
$lastNonEmpty = -1;
foreach ($perCase as $case => $list) {
    if ($list !== []) {
        $lastNonEmpty = $case;
    }
}
foreach ($perCase as $case => $list) {
    $kinds = array_map(static fn(array $record): string => $record['kind'], $list);
    $verdict['sequence'][$case] = implode(',', $kinds);
    if ($kinds !== array_slice(EXPECTED_SEQUENCE, 0, count($kinds))) {
        $problems[] = "sequence_not_prefix:{$case}";
        continue;
    }
    $complete = count($kinds) === count(EXPECTED_SEQUENCE);
    if ($case < $lastNonEmpty && ! $complete) {
        $problems[] = "sequence_incomplete_before_last:{$case}";
    }
    if ($verdict['target'] === 'pass' && ! $complete) {
        $problems[] = "sequence_incomplete_on_pass:{$case}";
    }
    // Every lifecycle call before the last one of the case threw (the test continues only on an exception).
    foreach ($list as $position => $record) {
        $lifecycle = in_array($record['kind'], ['READ', 'MUTATE', 'CLAIM', 'PUBLICATION'], true);
        $last = $position === count($list) - 1;
        if ($lifecycle && ! $isError($record) && ! ($last && $case === $lastNonEmpty && $verdict['target'] === 'fail')) {
            $problems[] = "lifecycle_call_did_not_throw:{$record['kind']}:{$case}";
        }
    }
}
if ($verdict['target'] === 'fail' && $lastNonEmpty >= 0) {
    $lastRecord = end($perCase[$lastNonEmpty]);
    $verdict['accepted_call'] = ['case' => $lastNonEmpty, 'kind' => $lastRecord['kind'], 'reply' => $lastRecord['reply'] ?? null];
}

// --- between READ and MUTATE: anything else touching the key (finding, not invalidity) ---
foreach ($perCase as $case => $list) {
    $read = null;
    $mutate = null;
    foreach ($list as $record) {
        $read = $record['kind'] === 'READ' ? $record : $read;
        $mutate = $record['kind'] === 'MUTATE' ? $record : $mutate;
    }
    if ($read === null || $mutate === null) {
        continue;
    }
    foreach ($records as $record) {
        if ($record['seq'] <= $read['seq'] || $record['seq'] >= $mutate['seq']) {
            continue;
        }
        if ($record['key'] === null || $record['key'] === $case) {
            $findings[] = ['between_read_and_mutate' => $case, 'kind' => $record['kind'], 'conn' => $record['conn'], 'args' => $record['args']];
        }
    }
    $probe = $mutate['probe'] ?? null;
    $verdict['mutate'][$case] = ['probe' => $probe, 'action' => $mutate['action'] ?? null, 'reply' => $mutate['reply'] ?? null, 't0' => $list[0]['t0'] ?? null];
    if ($probe === null) {
        $problems[] = "mutate_without_probe:{$case}";
    } elseif (! isset($mutate['action']) && ($probe['pttl'] <= 0 || $probe['exists'] !== 1)) {
        $findings[] = ['key_absent_before_mutate' => $case, 'probe' => $probe];
    }
    if ($probe !== null && $probe['evicted_keys'] !== 0) {
        $findings[] = ['evicted_keys' => $probe['evicted_keys'], 'case' => $case];
    }
}
$runIds = [];
foreach ($verdict['mutate'] ?? [] as $entry) {
    if (isset($entry['probe']['run_id'])) {
        $runIds[$entry['probe']['run_id']] = true;
    }
}
if (count($runIds) > 1) {
    $findings[] = ['run_id_changed' => array_keys($runIds)];
}

// --- arm D signature (LT-157-06 / 07) ---------------------------------------
if ($deleteCase !== null) {
    $entry = $verdict['mutate'][$deleteCase] ?? null;
    $reply = $entry['reply'] ?? null;
    $signature = [
        'accepted_message' => $verdict['target_failure']['accepted_message'] ?? false,
        'lines_3749_1546' => isset($verdict['target_failure']) && in_array(3749, $verdict['target_failure']['lines'], true) && in_array(1546, $verdict['target_failure']['lines'], true),
        'pttl_after_del' => $entry['action']['pttl_after'] ?? null,
        'reply_new_generation' => is_array($reply) && ($reply[0] ?? null) === 1 && ($reply[1] ?? null) === '9' && ($reply[4] ?? null) === 1,
        'accepted_case' => $verdict['accepted_call']['case'] ?? null,
    ];
    $signature['d_ok'] = $signature['accepted_message'] && $signature['lines_3749_1546'] && $signature['pttl_after_del'] === -2
        && $signature['reply_new_generation'] && $signature['accepted_case'] === $deleteCase;
    $verdict['d_signature'] = $signature;
}

$verdict['v_frame'] = $problems === [];
$verdict['problems'] = $problems;
$verdict['findings'] = $findings;
$verdict['valid'] = $verdict['v_frame'] && $verdict['v_port'] && $target !== null && (! $isL || $verdict['v_ord']);
$verdict['reproduced'] = $verdict['target'] === 'fail' && ($verdict['target_failure']['accepted_message'] ?? false);

// Keep the bulky files only where they are evidence.
$keep = ! $verdict['valid'] || $verdict['target'] !== 'pass' || $verdict['other_failures'] !== 0 || $findings !== [] || $deleteCase !== null;
if (! $keep) {
    @unlink($junitPath);
    @unlink($dir . '/phpunit.out');
}
echo json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
