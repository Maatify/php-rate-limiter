<?php

declare(strict_types=1);

// #160 phase 2 arm C — T-CLAIM mechanism (PLAN.md §3, §8). Nothing under src/ or
// tests/ changes: the store and RespRedisCommandExecutor are used as they are;
// the clocks below live only here. The four calls and their arguments are the
// test's own (RedisFullCapabilityStoreIntegrationTest L1070–L1097).
//
//   cdet   C-DET-1 (claim at T+1), C-DET-0 (control, all at T), C-DET-2 (T+1 from READ on): n trials each
//   cnat   C-NAT: n repetitions, the store's clock is a pass-through recorder of SystemClock(UTC)
//
// Usage: php c.php --root=<repo> --port=<redis port> --arm=<cdet|cnat> --n=<n> --out=<rows.jsonl>

namespace Flaky160\C;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$options = getopt('', ['root:', 'port:', 'arm:', 'n:', 'out:']);
require (string) $options['root'] . '/vendor/autoload.php';
$n = (int) $options['n'];
$out = fopen((string) $options['out'], 'wb');

/** Value set by the harness between calls; used only in cdet. */
final class SettableClock implements ClockInterface
{
    public int $reads = 0;
    private DateTimeImmutable $value;

    public function __construct(int $seconds)
    {
        $this->set($seconds);
    }

    public function set(int $seconds): void
    {
        $this->value = new DateTimeImmutable('@' . $seconds, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        $this->reads++;
        return $this->value;
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

/** Pass-through recorder: returns the real reading unchanged and records it (LT-160B-02). */
final class RecordingClock implements ClockInterface
{
    /** @var list<array{string, string}> */
    public array $reads = [];
    public string $label = '';
    private ClockInterface $inner;

    public function __construct()
    {
        $this->inner = new SystemClock(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        $reading = $this->inner->now();
        $this->reads[] = [$this->label, $reading->format('U.u')];
        return $reading;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->inner->getTimezone();
    }
}

function row($handle, array $row): void
{
    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

$executor = new RespRedisCommandExecutor('127.0.0.1', (int) $options['port']);
$id = str_repeat('a', 32);
$logical = 'lifecycle-current';

/** The test's four calls; $between(label) runs before each call (set the clock / label the recorder). */
$sequence = static function (RedisFullCapabilityStore $store, callable $before) use ($id, $logical): array {
    $before('MUTATE');
    $mutation = $store->mutateGenerationBoundScore($logical, null, null, 600, 8);
    $before('BLOCK');
    $transition = $store->blockWithPunishmentLifecycleTracking($logical, null, 1, $id, 2, 1, 21600, 2, 600, 86400);
    $before('READ');
    $during = $store->readGenerationBoundScoreState($logical, null);
    $before('PTTL');
    $before('CLAIM');
    $claim = $store->claimPostPunishmentReentry($logical, null, $id);

    return [
        'mutate_applied' => $mutation->applied,
        'block_applied' => $transition->applied,
        'reentry_in_block_result' => $transition->postPunishmentReentry?->id === $id,
        'read_present' => $during !== null,
        'read_reentry_null' => $during !== null && $during->postPunishmentReentry === null,
        'claim' => $claim,
    ];
};

if ($options['arm'] === 'cdet') {
    // variant => [seconds offset for MUTATE+BLOCK, for READ, for CLAIM]
    $variants = ['C-DET-1' => [0, 0, 1], 'C-DET-0' => [0, 0, 0], 'C-DET-2' => [0, 1, 1]];
    foreach ($variants as $variant => [$offsetBlock, $offsetRead, $offsetClaim]) {
        for ($i = 0; $i < $n; $i++) {
            $executor->execute(['FLUSHDB']);
            $time = $executor->execute(['TIME']);
            $t = (int) $time[0];
            $clock = new SettableClock($t);
            $store = new RedisFullCapabilityStore($executor, 'c-det-' . $variant . '-' . $i, $clock);
            $blockKey = 'maatify:rate-limiter:v1:' . hash('sha256', 'c-det-' . $variant . '-' . $i) . ':block:' . hash('sha256', $logical);
            $pttl = null;
            $result = $sequence($store, static function (string $label) use ($clock, $t, $offsetBlock, $offsetRead, $offsetClaim, $executor, $blockKey, &$pttl): void {
                match ($label) {
                    'MUTATE', 'BLOCK' => $clock->set($t + $offsetBlock),
                    'READ' => $clock->set($t + $offsetRead),
                    'PTTL' => $pttl = $executor->execute(['PTTL', $blockKey]),
                    'CLAIM' => $clock->set($t + $offsetClaim),
                };
            });
            $result += ['variant' => $variant, 'i' => $i, 't' => $t, 'pttl_before_claim' => $pttl, 'clock_reads' => $clock->reads];
            // a trial counts only if the block applied and the physical key was alive before the claim
            $result['valid'] = $result['mutate_applied'] && $result['block_applied'] && is_int($pttl) && $pttl > 0;
            row($out, $result);
        }
    }
} elseif ($options['arm'] === 'cnat') {
    for ($i = 0; $i < $n; $i++) {
        $executor->execute(['FLUSHDB']);
        $clock = new RecordingClock();
        $store = new RedisFullCapabilityStore($executor, 'c-nat', $clock);
        $result = $sequence($store, static function (string $label) use ($clock): void {
            $clock->label = $label;
        });
        // one read per call (PLAN §1.1); the PTTL pseudo-label is never read
        $times = [];
        foreach ($clock->reads as [$label, $reading]) {
            $times[$label][] = $reading;
        }
        row($out, [
            'i' => $i,
            't' => array_map(static fn(string $label): array => $times[$label] ?? [], ['MUTATE', 'BLOCK', 'READ', 'CLAIM']),
            'reads' => count($clock->reads),
            'applied' => $result['mutate_applied'] && $result['block_applied'],
            'read_reentry_null' => $result['read_reentry_null'],
            'claim' => $result['claim'],
        ]);
    }
} else {
    fwrite(STDERR, "unknown arm\n");
    exit(2);
}
fclose($out);
