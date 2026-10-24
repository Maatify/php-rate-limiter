<?php

declare(strict_types=1);

// #160 step M — V-TRANSFORM and synthetic detectors of the W2 instrument (m2b_lib.php). No Redis, no PHPUnit.
//   php m2b_check.php --root=<repo> --dir=<scratch dir>
// One JSON line per check {name, ok, ...}; exit status 1 when anything fails.
//  V1  the on-disk exception file hashes to M2b::EXC_SHA (its hash at main@b6f4e22, also checked against git);
//  V2  the evaluated exception text equals the original with m2b-transform.diff applied by `patch`;
//  V3  the evaluated store equals the original plus phase 2b's store-transform.diff (W1 edit unchanged);
//  V4  the generated subclass defines exactly two methods and each calls parent:: once;
//  D1  a throw from a known `new` site is attributed to that file:line and to the running test method;
//  D2  a throw with no test frame is attributed to '?' (never silently to a test);
//  D3  a fork child's counters are its own (reset on first use) and the parent's are not duplicated;
//  D4  the served exception class still carries the message and is a RateLimiterException.

$o = getopt('', ['root:', 'dir:']);
$root = (string) $o['root'];
$dir = (string) $o['dir'];
@mkdir($dir, 0777, true);
require __DIR__ . '/m2b_lib.php';
require $root . '/vendor/autoload.php';

use Flaky160M\M2b;

$ok = true;
$emit = static function (string $name, bool $pass, array $extra = []) use (&$ok): void {
    $ok = $ok && $pass;
    echo json_encode(['name' => $name, 'ok' => $pass] + $extra, JSON_UNESCAPED_SLASHES), "\n";
};

$excRel = 'src/Exception/RateLimitConcurrencyException.php';
$storeRel = 'src/Repository/Redis/RedisFullCapabilityStore.php';
$exc = (string) file_get_contents("$root/$excRel");
$git = shell_exec('git -C ' . escapeshellarg($root) . ' show b6f4e22:' . escapeshellarg($excRel) . ' 2>/dev/null');
$emit('V1 exception file matches the pin', hash('sha256', $exc) === M2b::EXC_SHA, ['git_b6f4e22_matches' => is_string($git) && $git !== '' ? hash('sha256', $git) === M2b::EXC_SHA : null]);

M2b::install($root, $dir);
$evaluated = (string) file_get_contents("$dir/RateLimitConcurrencyException.evaluated.php");
file_put_contents("$dir/orig.php", $exc);
exec('patch -s ' . escapeshellarg("$dir/orig.php") . ' < ' . escapeshellarg(__DIR__ . '/m2b-transform.diff') . ' 2>&1', $po, $pc);
$emit('V2 evaluated exception = original + diff', $pc === 0 && $evaluated === file_get_contents("$dir/orig.php"), ['patch_status' => $pc]);

$store = (string) file_get_contents("$root/$storeRel");
file_put_contents("$dir/store-orig.php", $store);
exec('patch -s ' . escapeshellarg("$dir/store-orig.php") . ' < ' . escapeshellarg(__DIR__ . '/store-transform.diff') . ' 2>&1', $po2, $pc2);
$emit('V3 evaluated store = original + W1 diff', $pc2 === 0 && file_get_contents("$dir/RedisFullCapabilityStore.evaluated.php") === file_get_contents("$dir/store-orig.php"), ['patch_status' => $pc2]);

$sub = (string) file_get_contents("$dir/RedisFullCapabilityStore.m2b.php");
preg_match_all('/public function (\w+)\(/', $sub, $names);
preg_match_all('/parent::(\w+)\(/', $sub, $parents);
$emit('V4 subclass = two overrides, each one parent:: call', $names[1] === ['mutateGenerationBoundScoreAt', 'blockWithPunishmentLifecycleTrackingAt'] && $parents[1] === $names[1], ['methods' => $names[1]]);

final class DetectorTest
{
    public function testSyntheticThrow(): array
    {
        $line = __LINE__ + 1;
        $e = new \Maatify\RateLimiter\Exception\RateLimitConcurrencyException('synthetic');
        return [$line, $e];
    }
}
$GLOBALS['__m2bDetectorLine'] = null;
[$line, $e] = (new DetectorTest())->testSyntheticThrow();
$last = M2b::$thrown[count(M2b::$thrown) - 1] ?? null;
$emit('D1 throw attributed to its new-site line and the test method', $last !== null && $last['site'] === 'm2b_check.php:' . $line && $last['test'] === DetectorTest::class . '::testSyntheticThrow', ['recorded' => $last, 'expected_line' => $line]);

$before = count(M2b::$thrown);
new \Maatify\RateLimiter\Exception\RateLimitConcurrencyException('no test frame');
$last = M2b::$thrown[count(M2b::$thrown) - 1];
$emit('D2 throw without a test frame is attributed to ?', count(M2b::$thrown) === $before + 1 && $last['test'] === '?', ['recorded' => $last]);

$parentCount = count(M2b::$thrown);
$pid = pcntl_fork();
if ($pid === 0) {
    new \Maatify\RateLimiter\Exception\RateLimitConcurrencyException('child');
    $mine = count(M2b::$thrown);
    file_put_contents("$dir/child.count", (string) $mine);
    exit(0);
}
pcntl_waitpid($pid, $st);
$emit('D3 fork child counts only its own throws', (int) file_get_contents("$dir/child.count") === 1 && count(M2b::$thrown) === $parentCount, ['child' => (int) file_get_contents("$dir/child.count"), 'parent' => count(M2b::$thrown), 'parent_before' => $parentCount]);

$ex = new \Maatify\RateLimiter\Exception\RateLimitConcurrencyException('msg-check');
$emit('D4 served class keeps message and parent type', $ex->getMessage() === 'msg-check' && $ex instanceof \Maatify\RateLimiter\Exception\RateLimiterException);

echo json_encode(['all_ok' => $ok]), "\n";
exit($ok ? 0 : 1);
