<?php

declare(strict_types=1);

// #160 phase 2b — A-DET (PLAN.md §3, §8). A harness script, not PHPUnit. The pipeline is built as the test builds
// it (fixture.php); the store is the W1 recording class, and the armed switch lives ONLY in ArmedStore below,
// a separate subclass used in this script alone (LT-160C-01 (b)).
//   php adet.php --root=<repo> --port=<redis port> --n=<trials per variant> --out=<rows.jsonl>
// Variants: 1 (inject: the publication answers "not applied" three times), 0 (control, no injection),
//           b (route b2: first publication not applied, and the harness lowers the K4 score below l2 between attempts).

$o = getopt('', ['root:', 'port:', 'n:', 'out:']);
$root = (string) $o['root'];
require __DIR__ . '/w1_bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/fixture.php';

use Flaky160B\{Analysis, Fixture, Keys, Rec};
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\Exception\RateLimitConcurrencyException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;

class ArmedStore extends RedisFullCapabilityStore
{
    public int $inject = 0;
    public bool $lowerAfterFirst = false;
    public int $injected = 0;

    public function blockWithPunishmentLifecycleTrackingAt(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds, int $now): PunishmentLifecycleTransitionDTO
    {
        if ($this->inject <= 0) {
            return parent::blockWithPunishmentLifecycleTrackingAt($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $now);
        }
        $this->inject--;
        $this->injected++;
        $t = Rec::begin('blockWithPunishmentLifecycleTrackingAt', [$currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $now]);
        $result = new PunishmentLifecycleTransitionDTO(false, null, null, null);
        Rec::end($t, $result);
        $this->__flaky160bObserve($currentKey, ++Rec::$pubCount);
        if ($this->lowerAfterFirst && $this->injected === 1) {
            $state = $this->readGenerationBoundScoreStateAt($currentKey, $previousKey, $now * 1000);
            $this->mutateGenerationBoundScoreAt($currentKey, $previousKey, $state, 86400, 1, $now * 1000);
        }

        return $result;
    }
}

function wilson(int $x, int $n): array
{
    $z = 1.959964;
    $p = $x / $n;
    $d = 1 + $z * $z / $n;
    $c = ($p + $z * $z / (2 * $n)) / $d;
    $h = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $d;
    return [round(max(0, $c - $h), 4), round(min(1, $c + $h), 4)];
}

$source = (string) file_get_contents($root . '/src/Service/EvaluationPipeline.php');
$throwLine = null;
foreach (explode("\n", $source) as $i => $line) {
    if (str_contains($line, "throw new RateLimitConcurrencyException('K4 punishment publication conflict budget exhausted.')")) {
        $throwLine = $i + 1;
    }
}
$message = 'K4 punishment publication conflict budget exhausted.';

$port = (int) $o['port'];
$n = (int) $o['n'];
$out = fopen((string) $o['out'], 'wb');
$summary = [];
foreach (['1', '0', 'b'] as $variant) {
    $hits = 0;
    $invalid = 0;
    for ($trial = 1; $trial <= $n; $trial++) {
        $ns = 'it-adet-' . bin2hex(random_bytes(6));
        $executor = Fixture::executor($port);
        $store = new ArmedStore($executor, $ns);
        $store->inject = $variant === '0' ? 0 : ($variant === 'b' ? 3 : 3);
        $store->lowerAfterFirst = $variant === 'b';
        $now = Fixture::redisNow($executor);
        Fixture::preState($store, $now);
        $before = Fixture::state($store);
        $pipeline = Fixture::pipeline($store, $now);
        Rec::$buf = [];
        $store->injected = 0;
        $caught = null;
        $decision = null;
        try {
            $decision = Fixture::command($pipeline)->decision;
        } catch (RateLimitConcurrencyException $e) {
            $caught = $e;
        }
        $records = Rec::$buf;
        usort($records, static fn(array $a, array $b): int => $a['t0'] <=> $b['t0'] ?: $a['s'] <=> $b['s']);
        $w = Analysis::worker($records);
        $after = Fixture::state($store);
        Fixture::cleanup($executor, $ns);

        $branchReached = $w['pubs'] >= 1;
        $row = [
            'variant' => $variant, 'trial' => $trial, 'caught' => $caught !== null, 'message' => $caught?->getMessage(), 'line' => $caught?->getLine(),
            'expected_line' => $throwLine, 'decision' => $decision, 'worker' => $w, 'before' => $before, 'after' => $after,
        ];
        if ($variant === '0') {
            $hit = $caught === null && $decision === 'HARD_BLOCK' && ($after['k4_block'] ?? 0) >= 2 && $w['pubs'] === 1 && $w['pub_non_applied'] === 0;
        } else {
            $expectedPubs = $variant === '1' ? 3 : 1;
            $k4After = $variant === '1' ? $before['k4'] + 3 : 1;
            $hit = $caught !== null && $caught->getMessage() === $message && $caught->getLine() === $throwLine
                && $w['conflict_site'] === 'publication' && $w['route'] === ($variant === '1' ? 'r3' : 'b2') && $w['pubs'] === $expectedPubs && $w['k'] === 1
                && $after['allowance'] === $before['allowance'] + 1 && $after['k4'] === $k4After && $after['k5'] === $before['k5'] + 2 && $after['k4_block'] === null;
        }
        $row['branch_reached'] = $branchReached;
        $row['hit'] = $hit;
        if (! $branchReached) {
            $invalid++;
            $row['invalid'] = true;
        } elseif ($hit) {
            $hits++;
        }
        fwrite($out, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
    }
    $valid = $n - $invalid;
    $summary[$variant] = ['n' => $n, 'valid' => $valid, 'invalid' => $invalid, 'hits' => $hits, 'wilson95' => $valid > 0 ? wilson($hits, $valid) : null];
}
fclose($out);
echo json_encode(['expected_throw_line' => $throwLine, 'summary' => $summary], JSON_UNESCAPED_SLASHES), "\n";
