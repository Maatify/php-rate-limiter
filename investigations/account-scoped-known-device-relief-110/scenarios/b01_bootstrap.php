<?php

declare(strict_types=1);

// B01 — bootstrap: can relief reach an office that starts with NO known device? 100 browsers, one NAT, one Firefox major, 14 days, one
// shared store from empty (state carries across days). Unlike H02 (PR #118) nothing is idealized: EVERY browser, known or not, goes through
// the pipeline every day; known browsers get the variant's relief view (a MODEL, lib/akdr.php header).
//
// Host establishment rule (the only way in, DEVICE_FINGERPRINT.md §4.3 "a prior successful authenticated session"): a browser becomes
// previously-verified for its own account after an ADMITTED check followed by a recorded successful login; it stays verified for the rest
// of the horizon. No client-sent identifier, no DeviceFP, no K5 presence and no site visit grants it.
//
// Runs (AKDR_B_RUNS): MAIN and the relief variants C1, C2, C3, D1 without an attacker; MAIN, C1, C2, C3 with the H02 credential-less sprayer
// NC (one attempt / 60 s, 08:50–10:00, fresh accounts) — the B5 pool-effect comparison. Modes LOW and MED.
require dirname(__DIR__) . '/lib/akdr.php';

emit('B01', ['row' => 'provenance'] + akdrProvenance());
foreach (['LOW', 'MED'] as $mode) {
    foreach (AKDR_B_RUNS as $label => [$variant, $attacker]) {
        $lab = new AkdrLab('login');
        $state = new AkdrReliefState();
        $origin = [];
        $known = array_fill(0, AKDR_B_SIZE, false);
        for ($day = 0; $day < AKDR_B_DAYS; $day++) {
            $startKnown = implode('', array_map(static fn(bool $k): string => $k ? '1' : '0', $known));
            $r = akdrOfficeDay($lab, $state, $origin, $day, AKDR_B_SIZE, $mode, $known, $attacker, $variant, 1102);
            foreach ($r['users'] as $idx => $u) {
                if ($u['success'] !== null) {
                    $known[$idx] = true;
                }
            }
            emit('B01', [
                'row' => 'day', 'run' => $label, 'mode' => $mode, 'variant' => $variant, 'attacker' => $attacker, 'day' => $day,
                'startKnown' => $startKnown,
                'attackReached' => $r['attack']['reached'], 'attackAttempts' => $r['attack']['n'],
                'causes' => $r['causes'],
                'tokens' => implode(' ', $r['tokens']),
            ]);
        }
    }
}
