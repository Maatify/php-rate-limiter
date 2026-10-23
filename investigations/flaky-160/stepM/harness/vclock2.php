<?php

declare(strict_types=1);

// #160 phase 2b — V-CLOCK-2 (PLAN.md §2, §8 LT-160C-01 (d), LT-160C-03): a sequential single-process run of
// the test's command through the pipeline on a FixedClock, with and without the W1 recorder; the end state and
// the decisions must be identical. Two child processes (one per mode), same fixed second T for both.
//   php vclock2.php --root=<repo> --port=<redis port> --dir=<out dir> [--commands=6]
// internal: --mode=with|without --t=<fixed second> (prints one JSON line)

$o = getopt('', ['root:', 'port:', 'dir:', 'commands::', 'mode::', 't::']);
$root = (string) $o['root'];
$port = (int) $o['port'];
$commands = (int) ($o['commands'] ?? 6);

if (isset($o['mode'])) {
    $mode = (string) $o['mode'];
    if ($mode === 'with') {
        putenv('FLAKY160B_DIR=' . $o['dir']);
        require __DIR__ . '/w1_bootstrap.php';
    } else {
        require $root . '/vendor/autoload.php';
    }
    require __DIR__ . '/lib.php';
    require __DIR__ . '/fixture.php';
    $ns = 'it-vclock2-' . bin2hex(random_bytes(6));
    $executor = Flaky160B\Fixture::executor($port);
    $store = new Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore($executor, $ns);
    $isRecording = $store instanceof Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStoreInner;
    Flaky160B\Fixture::preState($store, (int) $o['t']);
    $pipeline = Flaky160B\Fixture::pipeline($store, (int) $o['t']);
    $decisions = [];
    for ($i = 0; $i < $commands; $i++) {
        try {
            $r = Flaky160B\Fixture::command($pipeline);
            $decisions[] = [$r->decision, $r->level ?? null, $r->retryAfter ?? null];
        } catch (Maatify\RateLimiter\Exception\RateLimitConcurrencyException $e) {
            $decisions[] = ['conflict'];
        }
    }
    $state = Flaky160B\Fixture::state($store);
    Flaky160B\Fixture::cleanup($executor, $ns);
    echo json_encode(['mode' => $mode, 'recording_class' => $isRecording, 'decisions' => $decisions, 'state' => $state], JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

$dir = (string) $o['dir'];
@mkdir($dir, 0777, true);
// One fixed second for both modes, taken from Redis TIME once (the test reads it once per wave).
require $root . '/vendor/autoload.php';
$c = new Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor('127.0.0.1', $port);
$t = (int) $c->execute(['TIME'])[0];
$run = static function (string $mode) use ($root, $port, $dir, $commands, $t): array {
    $cmd = sprintf('XDEBUG_MODE=off php -d memory_limit=1G %s --root=%s --port=%d --dir=%s --commands=%d --mode=%s --t=%d',
        escapeshellarg(__FILE__), escapeshellarg($root), $port, escapeshellarg($dir), $commands, $mode, $t);
    $out = trim((string) shell_exec($cmd));
    $row = json_decode($out, true);
    if (! is_array($row)) {
        fwrite(STDERR, "vclock2 mode $mode failed: $out\n");
        exit(2);
    }
    return $row;
};
$without = $run('without');
$with = $run('with');
$same = $without['decisions'] === $with['decisions'] && $without['state'] === $with['state'];
$exercised = $without['state']['allowance'] >= 9 && ($without['state']['k4_block'] ?? 0) >= 2;
$res = [
    'fixed_second' => $t, 'commands' => $commands, 'same_decisions' => $without['decisions'] === $with['decisions'], 'same_state' => $without['state'] === $with['state'],
    'recording_class_without' => $without['recording_class'], 'recording_class_with' => $with['recording_class'], 'state' => $with['state'],
    'decisions_with' => array_count_values(array_column($with['decisions'], 0)), 'exercises_k4_block_level2' => $exercised,
    'ok' => $same && ! $without['recording_class'] && $with['recording_class'] && $exercised,
];
echo json_encode($res, JSON_UNESCAPED_SLASHES), "\n";
exit($res['ok'] ? 0 : 1);
