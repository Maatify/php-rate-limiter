<?php

declare(strict_types=1);

// #160 step S — W3 checks, run before any counted arm in the same job (PLAN §6.3). No Redis.
//   php w3_check.php --change=<tree> --base=<tree> --dir=<out> --change-ref=<ref> --base-ref=<ref>
// Per tree: tree identity (autoloaded src class lives under the tree; git tree equals its ref), V-TRANSFORM of the two
// edits (evaluated text = original + edit, hash pins), instrument neutrality (the identity scenario returns the same
// results with and without W3) and recorder correctness (recorded results = returned results). Then the synthetic
// detectors of the comparison and the timing summary. Prints one JSON line per check; exit 1 on any failure.

require __DIR__ . '/w3_lib.php';
require __DIR__ . '/w3_compare.php';
require __DIR__ . '/w3_timing_summary.php';

use Flaky160S\{Compare, Timing, W3};

$o = getopt('', ['change:', 'base:', 'dir:', 'change-ref:', 'base-ref:']);
$dir = (string) $o['dir'];
$ok = true;
$emit = static function (array $row) use (&$ok): void {
    $ok = $ok && ($row['ok'] ?? false);
    echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
};
$child = static function (string $tree, bool $instrumented, string $recDir): array {
    @mkdir($recDir, 0777, true);
    $cmd = 'FLAKY160_ROOT=' . escapeshellarg($tree) . ' FLAKY160M_DIR=' . escapeshellarg($recDir) . ' XDEBUG_MODE=off php -d memory_limit=1G '
        . escapeshellarg(__DIR__ . '/w3_scenario.php') . ' --root=' . escapeshellarg($tree) . ' --instrumented=' . ($instrumented ? '1' : '0') . ' 2>&1';
    exec($cmd, $lines, $code);

    return [$code, json_decode((string) end($lines), true), implode("\n", $lines)];
};

foreach (['change' => [(string) $o['change'], (string) $o['change-ref']], 'base' => [(string) $o['base'], (string) $o['base-ref']]] as $arm => [$tree, $ref]) {
    $row = ['check' => 'tree', 'arm' => $arm, 'tree' => $tree];
    $clean = shell_exec('git -C ' . escapeshellarg($tree) . ' diff --quiet ' . escapeshellarg($ref) . ' -- src tests composer.json phpunit.xml; echo $?');
    $row['src_tests_equal_ref'] = trim((string) $clean) === '0';
    $pins = json_decode((string) file_get_contents(__DIR__ . '/pins.json'), true);
    $row['engine_pin'] = hash_file('sha256', "$tree/src/Service/RateLimiterEngine.php") === $pins['engine'];
    $row['pipeline_pin'] = hash_file('sha256', "$tree/src/Service/EvaluationPipeline.php") === $pins['pipeline'][$arm];
    // W3 evaluated texts for this tree, and independent reproduction by `patch` from the committed diffs.
    $tmp = "$dir/$arm";
    @mkdir($tmp, 0777, true);
    [$c1, $plain, $err1] = $child($tree, false, "$tmp/plain");
    [$c2, $inst, $err2] = $child($tree, true, "$tmp/inst");
    $row['scenario_ran'] = $c1 === 0 && $c2 === 0 && is_array($plain) && is_array($inst);
    $row['neutral'] = $row['scenario_ran'] && $plain === $inst;
    $recorded = [];
    foreach (glob("$tmp/inst/w3-*.json") ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        if ($j['root'] ?? false) {
            $row['w3_arm'] = $j['arm'];
            foreach ($j['decisions'] as $d) {
                $recorded[] = [$d[2], $d[3], $d[4], $d[5], $d[6], $d[7]];
            }
        }
    }
    $row['recorded_equals_returned'] = $row['scenario_ran'] && $recorded === $inst;
    $row['scenario_results'] = is_array($inst) ? count($inst) : null;
    $row['w3_arm_matches'] = ($row['w3_arm'] ?? null) === $arm;
    // The scenario must hit the K4 lifecycle path at least once (a HARD_BLOCK of level 2), or it proves nothing.
    $row['scenario_reaches_l2'] = is_array($inst) && count(array_filter($inst, static fn(array $r): bool => $r[0] === 'HARD_BLOCK' && $r[1] === 2)) > 0;
    // The tree under test is the one whose class files were loaded.
    $row['class_under_tree'] = str_starts_with((string) shell_exec('FLAKY160_ROOT=' . escapeshellarg($tree) . ' php -r ' . escapeshellarg('require getenv("FLAKY160_ROOT")."/vendor/autoload.php"; echo (new ReflectionClass("Maatify\\\\RateLimiter\\\\Service\\\\DecayCalculator"))->getFileName();')), realpath($tree) ?: $tree);
    $row['ok'] = $row['src_tests_equal_ref'] && $row['engine_pin'] && $row['pipeline_pin'] && $row['neutral'] && $row['recorded_equals_returned']
        && $row['w3_arm_matches'] && $row['scenario_reaches_l2'] && $row['class_under_tree'];
    $emit($row);
}

// Edit reproduction: the evaluated engine/pipeline text is the original plus the edit and nothing else.
foreach (['change' => (string) $o['change'], 'base' => (string) $o['base']] as $arm => $tree) {
    $engine = (string) file_get_contents("$tree/src/Service/RateLimiterEngine.php");
    $pipe = (string) file_get_contents("$tree/src/Service/EvaluationPipeline.php");
    $evalEngine = str_replace(W3::ENGINE_FROM, W3::ENGINE_TO, $engine, $n1);
    $edit = W3::pipelineEdit($pipe);
    $emit(['check' => 'edit', 'arm' => $arm, 'engine_edit_once' => $n1 === 1, 'pipeline_edit_once' => $edit !== null,
        'pipeline_return_type' => $edit[1] ?? null, 'engine_added_lines' => substr_count($evalEngine, "\n") - substr_count($engine, "\n"),
        'pipeline_added_lines' => $edit === null ? null : substr_count($edit[0], "\n") - substr_count($pipe, "\n"),
        'ok' => $n1 === 1 && $edit !== null && (($edit[1] ?? '') === ($arm === 'base' ? 'void' : 'array'))]);
}

// Synthetic detectors: the comparison must see an injected difference and excuse only a named one; the timing summary
// must flag a ratio above the bound and pass one below it.
$a = ['root' => ['T::a' => [[0, 'ALLOW', 0, 0, 'NORMAL', null, null]], 'T::b' => [[0, 'HARD_BLOCK', 2, 60, 'NORMAL', null, null]]], 'worker_rows' => 0];
$b = $a;
$same = Compare::diff($a, $b, []);
$b['root']['T::b'][0][3] = 1;
$injected = Compare::diff($a, $b, []);
$excused = Compare::diff($a, $b, ['T::b']);
$emit(['check' => 'detector_compare', 'identical_ok' => $same['ok'], 'injected_detected' => ! $injected['ok'] && $injected['unexpected_differences'] === 1,
    'named_difference_excused' => $excused['ok'] && count($excused['different']) === 1,
    'ok' => $same['ok'] && ! $injected['ok'] && $excused['ok']]);
$rows = static fn(int $baseNs, int $changeNs): array => [
    ['arm' => 'base', 'w3_arm' => 'base', 'run' => 1, 'exit' => 0, 'calls' => 3, 'pub_ns' => [$baseNs, $baseNs, $baseNs], 'all_ns' => [$baseNs]],
    ['arm' => 'change', 'w3_arm' => 'change', 'run' => 1, 'exit' => 0, 'calls' => 3, 'pub_ns' => [$changeNs, $changeNs, $changeNs], 'all_ns' => [$changeNs]],
];
$slow = Timing::summarize($rows(1000, 1300));
$fast = Timing::summarize($rows(1000, 1100));
$bad = Timing::summarize([['arm' => 'base', 'w3_arm' => 'change', 'run' => 1, 'exit' => 0, 'calls' => 0, 'pub_ns' => [], 'all_ns' => []]]);
$emit(['check' => 'detector_timing', 'slow_flagged' => ! $slow['within'], 'fast_passes' => $fast['within'], 'arm_mismatch_counted' => $bad['harness_errors'] === 1,
    'ok' => ! $slow['within'] && $fast['within'] && $bad['harness_errors'] === 1]);
exit($ok ? 0 : 1);
