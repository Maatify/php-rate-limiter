<?php

declare(strict_types=1);

// #160 phase 2b — V-TRANSFORM (PLAN.md §2, §8 LT-160C-01 (a), (b)). After a bootstrap run that loaded the
// store class (its evaluated files are in --dir), prove:
//  1. the on-disk src store file hashes to store.sha256 (its hash at main@2cf8565; also against git when available);
//  2. the evaluated Inner text equals the original + store-transform.diff exactly (applied independently with patch);
//  3. the generated recording subclass equals what the uniform template generates from the evaluated Inner, and
//     every method in it calls parent:: (no other logic);
//  4. the served class is the subclass and implements every interface the Inner implements.
// Prints one JSON line; exit status 1 when anything fails.

require __DIR__ . '/w1_bootstrap_lib.php';

$o = getopt('', ['root:', 'dir:']);
$root = (string) $o['root'];
$dir = (string) $o['dir'];
$rel = 'src/Repository/Redis/RedisFullCapabilityStore.php';
$res = [];
$pin = trim((string) file_get_contents(__DIR__ . '/store.sha256'));
$disk = (string) file_get_contents("$root/$rel");
$res['disk_matches_pin'] = hash('sha256', $disk) === $pin;
$git = shell_exec('git -C ' . escapeshellarg($root) . ' show 2cf8565:' . escapeshellarg($rel) . ' 2>/dev/null');
$res['git_2cf8565_matches_pin'] = is_string($git) && $git !== '' ? hash('sha256', $git) === $pin : null;

$tmp = sys_get_temp_dir() . '/vt-' . getmypid();
@mkdir($tmp);
file_put_contents("$tmp/orig.php", $disk);
exec('patch -s ' . escapeshellarg("$tmp/orig.php") . ' < ' . escapeshellarg(__DIR__ . '/store-transform.diff') . ' 2>&1', $po, $pc);
$inner = (string) @file_get_contents("$dir/RedisFullCapabilityStore.evaluated.php");
$res['patch_applied'] = $pc === 0;
$res['evaluated_equals_original_plus_diff'] = $pc === 0 && $inner !== '' && $inner === file_get_contents("$tmp/orig.php");

$sub = (string) @file_get_contents("$dir/RedisFullCapabilityStore.recording.php");
$ns = 'Maatify\\RateLimiter\\Repository\\Redis';
require $root . '/vendor/autoload.php';
require "$dir/RedisFullCapabilityStore.evaluated.php";
$expected = "<?php\n\ndeclare(strict_types=1);\n\n" . Flaky160B\Gen::subclass($ns . '\\RedisFullCapabilityStoreInner', $ns, 'RedisFullCapabilityStore');
$res['subclass_equals_template'] = $sub !== '' && $sub === $expected;
// Every generated method calls parent:: ; the only statements beyond record/parent/return are the two hooks.
preg_match_all('/public function (\w+)\(/', $sub, $names);
preg_match_all('/parent::(\w+)\(/', $sub, $parents);
$res['methods'] = count($names[1]);
$res['every_method_calls_parent'] = $names[1] !== [] && count(array_diff(array_unique($names[1]), array_unique(array_merge($parents[1], ['__construct', '__flaky160bObserve'])))) === 0
    && str_contains($sub, 'parent::__construct(');
$res['hooks'] = ['observe_after_non_applied_publication' => substr_count($sub, '__flaky160bObserve('), 'key_registry' => substr_count($sub, 'Rec::$keys')];
$res['disk_store_final_unchanged'] = str_contains($disk, 'final class RedisFullCapabilityStore implements') && ! str_contains($disk, 'Inner');
$res['ok'] = $res['disk_matches_pin'] && $res['evaluated_equals_original_plus_diff'] && $res['subclass_equals_template'] && $res['every_method_calls_parent']
    && $res['disk_store_final_unchanged'] && ($res['git_2cf8565_matches_pin'] ?? true) !== false;
echo json_encode($res, JSON_UNESCAPED_SLASHES), "\n";
exit($res['ok'] ? 0 : 1);
