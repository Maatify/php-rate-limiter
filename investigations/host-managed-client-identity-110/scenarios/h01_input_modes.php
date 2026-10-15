<?php

declare(strict_types=1);

// H01 — how the EXISTING public inputs behave when a Host carries a host-issued browser id through them (input modes M0–M4), on the
// pinned post-DEC-021 main runtime (S0 rules + DEC-021). Login policy, `pre+out` Host flow (checkOnly, then record the outcome only if
// checkOnly allowed). Deterministic: controlled clock, fixed seeds, no wall clock, no randomness outside Lcg.
//
// Streams (each cell = one stream x one mode on a FRESH Lab, i.e. fresh stores):
//   OFF12   benign  12 browsers behind one NAT IP, one Firefox major, one account each, one arrival every 30 s, ~10 % typo then retry
//   OFF100  benign  100 browsers, same shape, one arrival every 18 s (30 min) — larger than the 50-member per-IP device cap
//   SPR_S   attack  one IP, one Firefox major, ONE stable bid, 200 distinct accounts, one failed attempt every 6 s
//   SPR_R   attack  same, a NEW bid on every attempt (identifier rotation)
//   BRU_S   attack  one IP, one account, 100 failed attempts every 30 s, one stable bid
//   BRU_R   attack  same, a NEW bid on every attempt
//   UAS     owner   one previously verified (host fact) browser, one account, 12 failures every 60 s, stable bid, stable UA
//   UAC     owner   same, the browser updates Firefox 130 -> 131 before failure 7 (bid unchanged)
//
// Metrics (per attempt, from the two public results of the `pre+out` flow):
//   check  = checkOnly decision: A allow / S soft / H hard. A denied check means the Host refuses BEFORE verifying the credential.
//   record = decision returned by recordSuccess / recordFailure ('-' when the check denied and nothing was recorded).
//   benign: `denied` = attempts whose checkOnly denied; `usersDenied` = users with at least one such attempt; `recordBlocked` = attempts
//           whose credential was verified but whose record command returned a block; `firstDenialBlockedKeys` = persisted K1..K5 blocks
//           active at a user's first denied check (attribution).
//   attack / owner: `reach` = attempts whose checkOnly allowed, i.e. the Host reached the verifier.
//   `check` / `record` strings keep every request so any delta between cells is exact.
require_once dirname(__DIR__) . '/lib/hmci.php';

$prov = hmciProvenance();
emit('h01', ['row' => 'provenance'] + $prov);

/** @return array{0: string, 1: string} check char, record char */
function decisionChars(array $r): array
{
    $steps = explode('>', $r['steps']);
    $char = static fn(string $step): string => str_ends_with($step, ':A') ? 'A' : $step[strpos($step, ':') + 1];

    return [$char($steps[0]), isset($steps[1]) ? $char($steps[1]) : '-'];
}

/** @return list<array{t: int, who: string, account: string, outcome: string}> */
function officeEvents(int $browsers, int $spacing, int $seed): array
{
    $rng = new Lcg($seed);
    $events = [];
    for ($i = 0; $i < $browsers; $i++) {
        $t = $i * $spacing;
        $who = sprintf('u%03d', $i);
        if ($rng->pct() <= 10) {
            $events[] = ['t' => $t, 'who' => $who, 'account' => "acct-$who", 'outcome' => 'fail'];
            $events[] = ['t' => $t + 15, 'who' => $who, 'account' => "acct-$who", 'outcome' => 'ok'];
        } else {
            $events[] = ['t' => $t, 'who' => $who, 'account' => "acct-$who", 'outcome' => 'ok'];
        }
    }
    usort($events, static fn(array $a, array $b): int => [$a['t'], $a['who']] <=> [$b['t'], $b['who']]);

    return $events;
}

foreach (['OFF12' => [12, 30], 'OFF100' => [100, 18]] as $stream => [$n, $spacing]) {
    foreach (HMCI_MODES as $mode) {
        $lab = new Lab('login');
        $check = '';
        $record = '';
        $deniedUsers = [];
        $attribution = [];
        $confidence = null;
        foreach (officeEvents($n, $spacing, 110) as $e) {
            $lab->at($e['t']);
            $c = hmciCtx($mode, OFFICE_NAT, FIREFOX_130, $e['account'], bid($e['who']));
            $r = $lab->attempt($c, 'pre+out', $e['outcome']);
            $confidence ??= $r['conf'];
            [$ch, $rc] = decisionChars($r);
            $check .= $ch;
            $record .= $rc;
            if ($ch !== 'A' && ! isset($deniedUsers[$e['who']])) {
                $deniedUsers[$e['who']] = true;
                $by = hmciBlockedKeys($lab, $c);
                $attribution[$by] = ($attribution[$by] ?? 0) + 1;
            }
        }
        ksort($attribution);
        emit('h01', [
            'row' => 'cell', 'stream' => $stream, 'class' => 'benign', 'mode' => $mode, 'conf' => $confidence,
            'attempts' => strlen($check), 'denied' => strlen($check) - substr_count($check, 'A'), 'users' => $n,
            'usersDenied' => count($deniedUsers), 'recordBlocked' => strlen(str_replace(['A', '-'], '', $record)),
            'firstDenialBlockedKeys' => $attribution, 'check' => $check, 'record' => $record,
        ]);
    }
}

$attacks = [
    'SPR_S' => ['n' => 200, 'every' => 6, 'accounts' => 'distinct', 'rotate' => false],
    'SPR_R' => ['n' => 200, 'every' => 6, 'accounts' => 'distinct', 'rotate' => true],
    'BRU_S' => ['n' => 100, 'every' => 30, 'accounts' => 'one', 'rotate' => false],
    'BRU_R' => ['n' => 100, 'every' => 30, 'accounts' => 'one', 'rotate' => true],
];
foreach ($attacks as $stream => $a) {
    foreach (HMCI_MODES as $mode) {
        $lab = new Lab('login');
        $check = '';
        $record = '';
        $confidence = null;
        for ($i = 0; $i < $a['n']; $i++) {
            $lab->at($i * $a['every']);
            $account = $a['accounts'] === 'one' ? 'acct-victim' : sprintf('acct-v%03d', $i);
            $c = hmciCtx($mode, ATTACK_IP, FIREFOX_130, $account, bid('attacker', $a['rotate'] ? $i : 0));
            $r = $lab->attempt($c, 'pre+out', 'fail');
            $confidence ??= $r['conf'];
            [$ch, $rc] = decisionChars($r);
            $check .= $ch;
            $record .= $rc;
        }
        emit('h01', [
            'row' => 'cell', 'stream' => $stream, 'class' => 'attack', 'mode' => $mode, 'conf' => $confidence,
            'attempts' => $a['n'], 'reach' => substr_count($check, 'A'), 'check' => $check, 'record' => $record,
        ]);
    }
}

foreach (['UAS' => false, 'UAC' => true] as $stream => $uaChange) {
    foreach (HMCI_MODES as $mode) {
        $lab = new Lab('login');
        $check = '';
        $record = '';
        $k5Keys = [];
        for ($i = 0; $i < 12; $i++) {
            $lab->at($i * 60);
            $ua = $uaChange && $i >= 6 ? FIREFOX_131 : FIREFOX_130;
            $c = hmciCtx($mode, OFFICE_NAT, $ua, 'acct-owner', bid('owner'), true);
            $r = $lab->attempt($c, 'pre+out', 'fail');
            [$ch, $rc] = decisionChars($r);
            $check .= $ch;
            $record .= $rc;
            $k5Keys[$lab->keys($c)['K5']] = true;
        }
        emit('h01', [
            'row' => 'cell', 'stream' => $stream, 'class' => 'owner', 'mode' => $mode,
            'attempts' => 12, 'reach' => substr_count($check, 'A'), 'distinctK5' => count($k5Keys), 'check' => $check,
            'record' => $record,
        ]);
    }
}
