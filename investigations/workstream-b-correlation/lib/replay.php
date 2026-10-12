<?php

declare(strict_types=1);

// Replays a fixture event stream through the REAL pipeline as a host-realistic flow:
// checkOnly -> (only if ALLOW) record outcome. Returns per-event results.

/**
 * @param list<array<string, mixed>> $events
 * @param 'pre+out'|'out'|'pre' $flow
 * @return list<array{blocked: bool, reachedVerification: bool, kind: string}>
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
        if ($flow !== 'out') {
            $r = $lab->run($c, 'check');
            $blocked = $r['d'] !== 'A';
            $reached = ! $blocked;
        }
        if ($flow !== 'pre' && ! $blocked) {
            $r = $lab->run($c, $ev['outcome']);
            $blocked = $r['d'] !== 'A';
            $reached = true;
        }
        $out[] = ['blocked' => $blocked, 'reachedVerification' => $reached, 'kind' => $ev['kind']];
    }

    return $out;
}

/**
 * @param list<array{blocked: bool, reachedVerification: bool, kind: string}> $res
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
