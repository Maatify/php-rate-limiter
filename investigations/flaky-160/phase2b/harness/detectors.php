<?php

declare(strict_types=1);

// #160 phase 2b — synthetic detectors (PLAN.md §7 step 2, §8 "Order"). Each prints one JSON line {name, ok, ...}.
//   php detectors.php --root=<repo> --run=<recorded passing run's rec dir with phpunit.out> --work=<scratch dir>
// ok:true means the detector behaved as registered: a record line removed -> the run is INVALID; a synthetic L2168
// trace -> r3 (one attempt -> b2); a tampered evaluated text -> V-TRANSFORM fails; a moved pin -> the bootstrap refuses.

require __DIR__ . '/lib.php';

use Flaky160B\{Analysis, Keys};

$o = getopt('', ['root:', 'run:', 'work:']);
$root = (string) $o['root'];
$run = (string) $o['run'];
$work = (string) $o['work'];
@mkdir($work, 0777, true);
$results = [];
$emit = static function (string $name, bool $ok, array $extra = []) use (&$results): void {
    $results[] = $row = ['name' => $name, 'ok' => $ok] + $extra;
    echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
};
$check = static function (string $dir) use ($root): array {
    $cmd = sprintf('php %s --arm=det --id=0 --dir=%s --exit=0', escapeshellarg(__DIR__ . '/anat_check.php'), escapeshellarg($dir));
    return json_decode(trim((string) shell_exec($cmd)), true);
};
$copy = static function (string $dst) use ($run): void {
    @mkdir($dst, 0777, true);
    foreach (glob("$run/*") ?: [] as $f) {
        if (is_file($f)) {
            copy($f, "$dst/" . basename($f));
        }
    }
};

// D0: the untouched record is valid (the baseline for every removal below).
$copy("$work/d0");
$base = $check("$work/d0");
$emit('D0 untouched record valid', $base['valid'] === true, ['problems' => $base['problems']]);

// D1: one line removed from a worker's record -> invalid. First, a middle, the last record, and the trailer; and a whole file.
$files = glob("$run/rec-[0-9]*.jsonl") ?: [];
sort($files);
$victim = $files[intdiv(count($files), 2)];
$lines = file($victim, FILE_IGNORE_NEW_LINES);
foreach (['first' => 0, 'middle' => intdiv(count($lines) - 1, 2), 'last_record' => count($lines) - 2, 'trailer' => count($lines) - 1] as $where => $idx) {
    $d = "$work/d1-$where";
    $copy($d);
    $l = $lines;
    unset($l[$idx]);
    file_put_contents("$d/" . basename($victim), implode("\n", $l) . "\n");
    $r = $check($d);
    $emit("D1 remove $where line", $r['valid'] === false, ['problems' => $r['problems']]);
}
$d = "$work/d1-file";
$copy($d);
unlink("$d/" . basename($victim));
$r = $check($d);
$emit('D1 remove a worker file', $r['valid'] === false, ['problems' => $r['problems']]);

// D2/D3: synthetic traces through the worker classifier.
$k4 = Keys::k4();
$k5 = Keys::k5();
$mk = static function (array $steps) use ($k4, $k5): array {
    $recs = [];
    $t = 1;
    foreach ($steps as $s) {
        $recs[] = ['s' => $t, 'd' => 0, 't0' => $t, 't1' => $t, 'm' => $s[0], 'p' => 'run', 'a' => $s[1], 'r' => $s[2], 'x' => null];
        $t++;
    }
    return $recs;
};
$base = [
    ['incrementBudgetAt', [Keys::allowance(), 86400, 1, 1], ['_' => 'BudgetStateDTO', 'count' => 9]],
    ['mutateGenerationBoundScoreAt', [$k4, null, null, 86400, 9, 1000], ['_' => 'GenerationBoundScoreMutationDTO', 'applied' => true]],
    ['incrementAt', [$k5, 86400, 2, 1], 22],
];
$pubNo = ['blockWithPunishmentLifecycleTrackingAt', [$k4, null, 1], ['_' => 'PunishmentLifecycleTransitionDTO', 'applied' => false]];
$readOk = ['readGenerationBoundScoreStateAt', [$k4, null, 1000], ['_' => 'GenerationBoundScoreStateDTO', 'generation' => 4]];
$r3 = Analysis::worker($mk([...$base, $pubNo, $readOk, $pubNo, $readOk, $pubNo, $readOk]));
$emit('D2 synthetic L2168 trace (3 attempts) is r3', $r3['conflict'] === true && $r3['route'] === 'r3' && $r3['k'] === 1 && $r3['conflict_site'] === 'publication', $r3);
$b2 = Analysis::worker($mk([...$base, $pubNo, $readOk]));
$emit('D3 one attempt then a read is b2', $b2['route'] === 'b2' && $b2['conflict'] === true, $b2);
$b1 = Analysis::worker($mk([...$base, $pubNo, ['readGenerationBoundScoreStateAt', [$k4, null, 1000], null]]));
$emit('D3b one attempt then a null read is b1', $b1['route'] === 'b1', $b1);
$ok = Analysis::worker($mk([...$base, ['blockWithPunishmentLifecycleTrackingAt', [$k4, null, 1], ['_' => 'PunishmentLifecycleTransitionDTO', 'applied' => true]]]));
$emit('D4 an applied publication is not a conflict', $ok['conflict'] === false && $ok['route'] === null, $ok);
$un = Analysis::worker($mk([['incrementBudgetAt', [Keys::allowance(), 86400, 1, 1], ['count' => 3]]]));
$emit('D5 a unit with no K5 and no conflict is visible', $un['u'] === 1 && $un['k'] === 0 && $un['conflict'] === false, $un);

// D6: V-TRANSFORM tamper: one byte appended to the evaluated text -> fails. (Needs a bootstrap-produced dir: --run/../../vt.)
$vt = "$work/vt";
@mkdir($vt, 0777, true);
$boot = sprintf('FLAKY160B_DIR=%s php -r %s', escapeshellarg($vt), escapeshellarg('require "' . __DIR__ . '/w1_bootstrap.php"; class_exists("Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore");'));
shell_exec($boot);
$vtRun = static fn(): array => json_decode(trim((string) shell_exec(sprintf('php %s --root=%s --dir=%s', escapeshellarg(__DIR__ . '/vtransform.php'), escapeshellarg($root), escapeshellarg($vt)))), true);
$emit('D6a untampered V-TRANSFORM passes', ($vtRun())['ok'] === true);
file_put_contents("$vt/RedisFullCapabilityStore.evaluated.php", ' ', FILE_APPEND);
$emit('D6b one byte appended to the evaluated text fails V-TRANSFORM', ($vtRun())['ok'] === false);
shell_exec($boot);
$sub = (string) file_get_contents("$vt/RedisFullCapabilityStore.recording.php");
file_put_contents("$vt/RedisFullCapabilityStore.recording.php", str_replace('return $__r;', 'return $__r ?? null;', $sub));
$emit('D6c a changed subclass body fails V-TRANSFORM', ($vtRun())['ok'] === false);

// D7: a moved pin makes the bootstrap refuse (exit 3), nothing is served.
$h = "$work/harness-copy";
@mkdir($h, 0777, true);
foreach (['w1_bootstrap.php', 'w1_bootstrap_lib.php', 'store-transform.diff'] as $f) {
    copy(__DIR__ . "/$f", "$h/$f");
}
file_put_contents("$h/store.sha256", str_repeat('0', 64) . "\n");
// the copy lives in the same directory depth as the real one only through the root override below
$code = 0;
exec(sprintf('cd %s && php -r %s 2>/dev/null', escapeshellarg($root), escapeshellarg('require "' . $h . '/w1_bootstrap_lib.php"; \\Flaky160B\\W1::install("' . $root . '", null);')), $x, $code);
$emit('D7 a moved pin makes the bootstrap exit 3', $code === 3, ['exit' => $code]);

$bad = array_filter($results, static fn(array $r): bool => $r['ok'] !== true);
echo json_encode(['detectors' => count($results), 'failed' => count($bad)]), "\n";
exit($bad === [] ? 0 : 1);
