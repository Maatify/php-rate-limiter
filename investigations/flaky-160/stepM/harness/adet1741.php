<?php

declare(strict_types=1);

// #160 step M — M1 A-DET-style row for the first throw site, `EvaluationPipeline` L1741
// (`RateLimitConcurrencyException('K4 generation mutation conflict budget exhausted.')`). Same construction as
// phase 2b's adet.php (copied unchanged next to this file): the pipeline is built as the test builds it
// (fixture.php), the store is the W1 recording class, and the armed switch lives ONLY in ArmedStore below.
//   php adet1741.php --root=<repo> --port=<redis port> --n=<trials per variant> --out=<rows.jsonl>
// Variants: 1 (inject: the K4 mutation answers "not applied" three times), 0 (control, no injection).
// Pre-state (fixture.php preState, same as A-DET): allowance 8, K4 score 6. The next failure of the test's device
// takes the allowance to 9 > 8, so K4 is scored (delta k4_failure) and the mutation loop at L1731-L1740 is reached.
// Hit rule (registered in PLAN.md §3): exception with the L1741 message and `getLine()` = the line of that `throw`
// found by scanning the file; 3 recorded mutation calls, all not applied, 0 publication calls; in Redis after the
// exception: allowance +1, the K1 score higher than before (k1_spray = 5 for this command, calculateDeltas L2883-L2936;
// K1 is processed before K4), K2 and K3 unchanged (their deltas are 0 for this command), K4 and K5 values unchanged
// (K5 is processed after K4, so its +2 is not reached), no K4 and no K5 block.

$o = getopt('', ['root:', 'port:', 'n:', 'out:']);
$root = (string) $o['root'];
require __DIR__ . '/w1_bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/fixture.php';

use Flaky160B\{Analysis, Fixture, Keys, Rec};
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Exception\RateLimitConcurrencyException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\SourcePrefix;

class ArmedStore extends RedisFullCapabilityStore
{
    public int $inject = 0;

    public function mutateGenerationBoundScoreAt(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue, int $nowMilliseconds): GenerationBoundScoreMutationDTO
    {
        if ($this->inject <= 0) {
            return parent::mutateGenerationBoundScoreAt($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, $nowMilliseconds);
        }
        $this->inject--;
        $t = Rec::begin('mutateGenerationBoundScoreAt', [$currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, $nowMilliseconds]);
        $result = new GenerationBoundScoreMutationDTO(false, null);
        Rec::end($t, $result);
        Rec::$keys[$currentKey] = true;

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
    if (str_contains($line, "throw new RateLimitConcurrencyException('K4 generation mutation conflict budget exhausted.')")) {
        $throwLine = $i + 1;
    }
}
$message = 'K4 generation mutation conflict budget exhausted.';

// K1-K3 identities (EvaluationPipeline::buildKeys L2804-L2820): scope = source prefix, + normalized UA, + fingerprint.
$prefix = SourcePrefix::of('198.51.100.60', 64);
$k1 = Keys::k('login_protection', 'k1', $prefix);
$k2 = Keys::k('login_protection', 'k2', $prefix . ':chrome/123');
$k3 = Keys::k('login_protection', 'k3', $prefix . ':concurrent-fp');

$scores = static fn(RedisFullCapabilityStore $s): array => [
    'k1' => $s->get($k1)->value ?? 0, 'k2' => $s->get($k2)->value ?? 0, 'k3' => $s->get($k3)->value ?? 0,
];

$port = (int) $o['port'];
$n = (int) $o['n'];
$out = fopen((string) $o['out'], 'wb');
$summary = [];
foreach (['1', '0'] as $variant) {
    $hits = 0;
    $invalid = 0;
    for ($trial = 1; $trial <= $n; $trial++) {
        $ns = 'it-adet1741-' . bin2hex(random_bytes(6));
        $executor = Fixture::executor($port);
        $store = new ArmedStore($executor, $ns);
        $now = Fixture::redisNow($executor);
        Fixture::preState($store, $now);
        $store->inject = $variant === '1' ? 3 : 0; // armed only after the pre-state (it uses the mutation method too)
        $before = Fixture::state($store) + ['scores' => $scores($store)];
        $pipeline = Fixture::pipeline($store, $now);
        Rec::$buf = [];
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
        $after = Fixture::state($store) + ['scores' => $scores($store)];
        Fixture::cleanup($executor, $ns);

        $branchReached = $w['muts'] >= 1;
        $row = [
            'variant' => $variant, 'trial' => $trial, 'caught' => $caught !== null, 'message' => $caught?->getMessage(), 'line' => $caught?->getLine(),
            'expected_line' => $throwLine, 'decision' => $decision, 'worker' => $w, 'before' => $before, 'after' => $after,
        ];
        if ($variant === '0') {
            $hit = $caught === null && $decision === 'HARD_BLOCK' && $w['muts'] === 1 && $w['pubs'] >= 1 && ($after['k4_block'] ?? 0) >= 2;
        } else {
            $up = static fn(string $k): bool => $after['scores'][$k] > $before['scores'][$k];
            $hit = $caught !== null && $caught->getMessage() === $message && $caught->getLine() === $throwLine
                && $w['muts'] === 3 && $w['pubs'] === 0
                && $after['allowance'] === $before['allowance'] + 1
                && $up('k1') && ! $up('k2') && ! $up('k3')
                && $after['k4'] === $before['k4'] && $after['k5'] === $before['k5']
                && $after['k4_block'] === null && $after['k5_block'] === null;
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
