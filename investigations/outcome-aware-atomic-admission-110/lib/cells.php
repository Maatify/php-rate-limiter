<?php

declare(strict_types=1);

// #110 OAAA — catalogue of every matrix cell scenarios/a01_matrix.php must emit PER ARM, and the experimental parameters (mirrors of the
// patch constants). report.php validates against it (a missing cell is missing evidence, never a silent skip). No dependency on the Lab.

const OAAA_ARMS_LIST = ['S0', 'OAAA'];

/** Experimental OAAA parameters (pre-registered in SECURITY_ENVELOPE.md; identical to the private constants of the patched pipeline). */
const OAAA_PARAMS = [
    'scopeEpochUnits' => 2,
    'scopeEpochSeconds' => 600,
    'scopeDayUnits' => 6,
    'daySeconds' => 86400,
    'globalEpochUnits' => 12,
    'globalDayUnits' => 48,
    'tupleSeconds' => 600,
    'creditWindowSeconds' => 600,
    'creditMembers' => 2,
    'refundFailures' => 2,
];

/** @return array<string, string> stream id => class (benign | attack | both | evasion | poisoning) */
function oaaaStreamClasses(): array
{
    return gnhStreamClasses() + [
        // this study (lib/oaaa.php): attackers aimed at OAAA itself
        'OWN1' => 'attack', 'OWN2' => 'attack', 'OWN6' => 'attack', 'OWN4' => 'attack', 'OWNK2' => 'attack',
        'TGT' => 'both', 'TGTM' => 'both', 'TGTL' => 'both',
    ];
}

/** Protection cells named by the task (§7) plus the study's own; every one is measured, none is dropped. */
function oaaaTaskProtectionStreams(): array
{
    return ['ABA_A', 'SP1n', 'SP1f', 'SP2', 'SP3', 'SP4', 'SP4u', 'SP4b', 'SP5', 'SP5c', 'SP6', 'K1R4', 'X1', 'X2', 'X7', 'Y1', 'Y2a', 'Y7', 'Y9a', 'Y9b',
        'D5', 'D7', 'TMS', 'TMSd', 'TMSs', 'XN1', 'XN1n', 'XN1k', 'OWN1', 'OWN2', 'OWN6', 'OWN4', 'OWNK2'];
}

/** Fairness cells named by the task (§4 Fairness Target), grouped by issue. */
function oaaaTaskFairnessStreams(): array
{
    return [
        '#97' => ['D1', 'L1', 'L1f', 'X2L'],
        '#100' => ['ABA_B', 'ABA_B1', 'GAP', 'N12'],
        '#106' => ['Nu4', 'Nu6', 'Nu12', 'D2', 'MIX', 'MIXN', 'MIXT', 'N12T'],
    ];
}

/** Streams whose attacker shares a scope with legitimate users ("both"): the attack and fairness halves are both judged. */
function oaaaMixedStreams(): array
{
    return array_keys(array_filter(oaaaStreamClasses(), static fn(string $c): bool => $c === 'both'));
}

/** @return list<array{0: string, 1: string, 2: string}> [stream, shape, flow] */
function oaaaMatrixCells(): array
{
    $cells = [];
    foreach (array_keys(oaaaStreamClasses()) as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (['D2', 'N12', 'Nu12', 'Nu12T', 'SP1n', 'SP3', 'MIXN', 'ABA_B', 'ABA_A', 'OTPB', 'TMSd', 'XN1', 'OWN1'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'otp', $flow];
        }
    }
    foreach (['D2', 'SP1n', 'SP3', 'MIXN', 'ABA_A', 'XN1n', 'OWN1'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'login-anon', $flow];
        }
    }
    // DEC-008 custom authentication policy (the Login preset under another name).
    foreach (['D2', 'Nu12', 'ABA_B', 'SP1n', 'ABA_A', 'OWN1'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'login_custom', $flow];
        }
    }
    // API_OVERUSE (built-in and the DEC-008 custom subclass): OAAA must leave them byte-identical to S0.
    foreach (['L1', 'L1f', 'D2', 'X1', 'X7', 'Y7', 'ABA_A', 'XN1', 'OWN1'] as $id) {
        foreach (['api', 'api_custom'] as $shape) {
            foreach (['pre+out', 'out'] as $flow) {
                $cells[] = [$id, $shape, $flow];
            }
        }
    }

    return $cells;
}

/** Integer fields every matrix cell must carry (fail-closed validation). */
function oaaaCellFields(): array
{
    return [
        'requests', 'legitAttempts', 'legitDenied', 'ownAttempts', 'ownDenied', 'attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackFailingAttempts', 'attackFailingReached',
        'attackDistinctSubjectsVerified', 'attackReachedMaxPer60', 'attackReachedMaxPer600', 'attackReachedMaxPer1800', 'attackReachedSlidingMax600',
        'attackReachedSlidingMax1800', 'attackScopes', 'blockIssuances', 'blockReissues', 'maxLevel', 'maxRetryAfter', 'reservationsGranted', 'attackReservationsGranted',
        'reservationsRefused', 'completionsPaired', 'reservationCancels',
    ];
}
