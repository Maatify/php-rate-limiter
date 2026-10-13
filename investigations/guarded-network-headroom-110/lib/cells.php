<?php

declare(strict_types=1);

// #110 Guarded Network Headroom — catalogue of every matrix cell scenarios/g01_matrix.php must emit PER ARM. report.php validates against
// it (a missing cell is missing evidence, never a silent skip). No dependency on the Lab.

const GNH_ARMS_LIST = ['S0', 'TH5', 'GNH5'];
const GNH_CANDIDATE_ARMS = ['TH5', 'GNH5'];
/** Experimental headroom budget of arm GNH5 per scope and fixed 600 s window, in evaluated commands (mirrors the patch constant). */
const GNH_BUDGET_PER_RULE = ['spray' => 20, 'churn' => 12, 'dilution' => 24];

/** @return array<string, string> stream id => class (benign | attack | both | evasion | poisoning) */
function gnhStreamClasses(): array
{
    return [
        // #108 K1/NAT streams (k1-credential-spray-nat/lib/k1.php), unchanged
        'D2' => 'benign', 'N3' => 'benign', 'N4' => 'benign', 'N5' => 'benign', 'N6' => 'benign', 'N12' => 'benign', 'N12T' => 'benign', 'N12M' => 'benign',
        'Nu3' => 'benign', 'Nu4' => 'benign', 'Nu5' => 'benign', 'Nu6' => 'benign', 'Nu12' => 'benign', 'Nu12T' => 'benign', 'Nu12M' => 'benign',
        'SP1f' => 'attack', 'SP1n' => 'attack', 'SP3' => 'attack', 'SP6' => 'attack', 'SP2' => 'attack', 'SP4' => 'attack', 'SP4u' => 'attack', 'SP4b' => 'attack', 'SP5' => 'attack',
        'MIX' => 'both', 'MIXT' => 'both', 'MIXU' => 'both', 'MIXN' => 'both',
        // #104 anti-evasion streams (workstream-b-anti-evasion/fixtures/anti_evasion.php), unchanged
        'L1' => 'benign', 'L1f' => 'benign', 'L2' => 'benign', 'L3' => 'benign', 'L4' => 'benign',
        'X1' => 'evasion', 'X2' => 'evasion', 'X2L' => 'both', 'X3' => 'evasion', 'X4' => 'poisoning', 'X5' => 'evasion', 'X6' => 'poisoning', 'X7' => 'evasion', 'X8' => 'both',
        'Y1' => 'evasion', 'Y2a' => 'evasion', 'Y2b' => 'evasion', 'Y2c' => 'evasion', 'Y2c2' => 'evasion', 'Y4b' => 'poisoning', 'Y4c' => 'poisoning', 'Y4d' => 'poisoning',
        'Y4e' => 'poisoning', 'Y4f' => 'poisoning', 'Y6b' => 'poisoning', 'Y6c' => 'poisoning', 'Y7' => 'evasion', 'Y9a' => 'evasion', 'Y9b' => 'evasion',
        // #105 design streams (workstream-b-anti-evasion/design/lib/dsg.php), unchanged
        'D1' => 'benign', 'D5' => 'attack', 'D6' => 'both', 'D7' => 'attack',
        // #110 plan-1 streams (nat-remediation-110/lib/oqc.php), unchanged
        'ABA_B' => 'benign', 'ABA_B1' => 'benign', 'ABA_A' => 'attack', 'K1R4' => 'attack', 'SP5c' => 'attack', 'GAP' => 'benign', 'OTPB' => 'benign',
        // #113 trusted multi-session streams (trusted-multi-session-s0/lib/tms.php), unchanged
        'TMS' => 'attack', 'TMSd' => 'attack', 'TMSs' => 'attack', 'TMR' => 'attack', 'TMX' => 'attack', 'TMU' => 'attack', 'TPAIRT' => 'both', 'TPAIRM' => 'both',
        // this study (lib/gnh.php): attacks aimed at the x5 headroom itself, a budget-burning neighbour, previously-verified office
        'XN1' => 'attack', 'XN1n' => 'attack', 'XN1k' => 'attack', 'BURN' => 'both', 'N12V' => 'benign',
    ];
}

/** Protection cells named by the task (§6); every one is measured, none is dropped. */
function gnhTaskProtectionStreams(): array
{
    return ['ABA_A', 'SP1n', 'SP1f', 'SP2', 'SP3', 'SP4', 'SP4u', 'SP4b', 'SP5', 'SP5c', 'SP6', 'K1R4', 'X1', 'X2', 'X7', 'Y1', 'Y2a', 'Y7', 'Y9a', 'Y9b', 'D5', 'D7', 'TMS', 'TMSd', 'TMSs'];
}

/** Fairness cells named by the task (§6). */
function gnhTaskFairnessStreams(): array
{
    return ['D1', 'D2', 'L1', 'L1f', 'N3', 'N6', 'N12', 'N12T', 'Nu4', 'Nu6', 'Nu12', 'ABA_B', 'OTPB', 'MIX', 'MIXN', 'MIXT'];
}

/** @return list<array{0: string, 1: string, 2: string}> [stream, shape, flow] */
function gnhMatrixCells(): array
{
    $cells = [];
    foreach (array_keys(gnhStreamClasses()) as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $cells[] = [$id, 'login', $flow];
        }
    }
    foreach (['D2', 'N12', 'Nu12', 'Nu12T', 'SP1n', 'SP3', 'MIXN', 'ABA_B', 'ABA_A', 'OTPB', 'TMSd', 'XN1'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'otp', $flow];
        }
    }
    foreach (['D2', 'SP1n', 'SP3', 'MIXN', 'ABA_A', 'XN1n'] as $id) {
        foreach (['pre+out', 'out'] as $flow) {
            $cells[] = [$id, 'login-anon', $flow];
        }
    }
    // API_OVERUSE (built-in and the DEC-008 custom subclass): the patched rules (churn / dilution) are shared by every policy.
    foreach (['L1', 'L1f', 'D2', 'X1', 'X7', 'Y7', 'ABA_A', 'XN1'] as $id) {
        foreach (['api', 'api_custom'] as $shape) {
            foreach (['pre+out', 'out'] as $flow) {
                $cells[] = [$id, $shape, $flow];
            }
        }
    }

    return $cells;
}

/** Integer fields every matrix cell must carry (fail-closed validation). */
function gnhCellFields(): array
{
    return [
        'requests', 'legitAttempts', 'legitDenied', 'attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDistinctSubjectsVerified',
        'attackReachedMaxPer600', 'correlationIssuances', 'blockIssuances', 'blockReissues', 'maxLevel', 'maxRetryAfter', 'headroomAdmissions',
        'budgetMax', 'peakCorrelationKeys', 'peakCorrelationMembers',
    ];
}
