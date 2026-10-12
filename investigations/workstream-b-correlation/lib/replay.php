<?php

declare(strict_types=1);

// Replays a fixture event stream through the REAL pipeline as a host-realistic flow:
// checkOnly -> (only if ALLOW) record outcome. Returns per-event results including every step and persisted K2/K3 state.

/**
 * @param list<array<string, mixed>> $events
 * @param 'pre+out'|'out'|'pre' $flow
 * @return list<array{blocked: bool, reachedVerification: bool, kind: string, steps: list<array{cmd: string, blocked: bool, level: ?int, retry: ?int}>, k2: ?int, k3: ?int}>
 */
function replayStream(Lab $lab, array $events, string $flow = 'pre+out'): array
{
    $uaMap = ['chrome126' => CHROME, 'chrome127' => CHROME_B];
    $out = [];
    foreach ($events as $ev) {
        $lab->at($ev['t']);
        $c = $lab->ctx($ev['ip'], $uaMap[$ev['ua']] ?? CHROME, $ev['acct'], $ev['fp'] === null ? null : fp($ev['fp']));
        $blocked = false;
        $reached = false;
        $steps = [];
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $steps[] = ['cmd' => 'check', 'blocked' => $r['d'] !== 'A', 'level' => $r['lvl'], 'retry' => $r['retry']];
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
        }
        if ($flow !== 'pre' && ! $blocked) {
            $r = $lab->run($c, $ev['outcome']);
            $steps[] = ['cmd' => 'record', 'blocked' => $r['d'] !== 'A', 'level' => $r['lvl'], 'retry' => $r['retry']];
            $blocked = $r['d'] !== 'A';
            $reached = true;
        }
        $now = $lab->clock->now()->getTimestamp();
        $keys = $lab->keys($c);
        $remaining = static function (Lab $lab, ?string $key, int $now): ?int {
            $b = $key === null ? null : $lab->store->checkBlock($key);

            return $b === null ? null : $b->expiresAt - $now;
        };
        $out[] = [
            'blocked' => $blocked,
            'reachedVerification' => $reached,
            'kind' => $ev['kind'],
            'steps' => $steps,
            'k2' => $remaining($lab, $keys['K2'], $now),
            'k3' => $remaining($lab, $keys['K3'], $now),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $res
 * @return array<string, mixed>
 */
function summarize(array $res): array
{
    $legit = array_filter($res, static fn(array $r): bool => $r['kind'] === 'legit');
    $atk = array_filter($res, static fn(array $r): bool => $r['kind'] === 'attack');

    return [
        'legitBlocked' => count(array_filter($legit, static fn(array $r): bool => $r['blocked'])) . '/' . count($legit),
        'attackBlocked' => count(array_filter($atk, static fn(array $r): bool => $r['blocked'])) . '/' . count($atk),
        'attackReachedVerification' => count(array_filter($atk, static fn(array $r): bool => $r['reachedVerification'])),
    ];
}
