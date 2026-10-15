<?php

declare(strict_types=1);

// Generates expected/summary.md and expected/observations.jsonl from expected/h01_input_modes.jsonl (or the file given as argv[1]).
// Fail-closed: exit 5 on missing / malformed / mislabelled evidence, a wrong runtime hash, or a missing cell. The observations are
// DESCRIPTIVE checks of the S0-on-main runtime (no candidate exists in this study); a PASS is a reproduced fact, never a security
// acceptance of any direction.
require_once __DIR__ . '/lib/hmci.php';

$in = $argv[1] ?? __DIR__ . '/expected/h01_input_modes.jsonl';
$outDir = $argv[2] ?? __DIR__ . '/expected';
$fail = static function (string $why): never {
    fwrite(STDERR, "report: EVIDENCE INVALID — $why\n");
    exit(5);
};
if (! is_file($in) || filesize($in) === 0) {
    $fail("missing or empty $in");
}

$cells = [];
$prov = null;
foreach (file($in, FILE_IGNORE_NEW_LINES) as $n => $line) {
    try {
        $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $fail('malformed JSON at line ' . ($n + 1));
    }
    if (($r['s'] ?? null) !== 'h01') {
        $fail('foreign scenario label at line ' . ($n + 1));
    }
    if ($r['row'] === 'provenance') {
        $prov = $r;
        continue;
    }
    foreach (['stream', 'class', 'mode', 'attempts', 'check', 'record'] as $field) {
        if (! array_key_exists($field, $r)) {
            $fail("missing field $field at line " . ($n + 1));
        }
    }
    if (strlen($r['check']) !== $r['attempts'] || strlen($r['record']) !== $r['attempts']) {
        $fail('per-request strings do not match attempts at line ' . ($n + 1));
    }
    $cells[$r['stream']][$r['mode']] = $r;
}
if ($prov === null || $prov['level'] !== 'S0_ON_MAIN_RUNTIME_OBSERVED' || $prov['baseSha'] !== HMCI_BASELINE_SHA || $prov['patched'] !== false) {
    $fail('missing or wrong provenance row');
}
foreach (HMCI_RUNTIME_SHA256 as $class => $sha) {
    $short = substr($class, strrpos($class, '\\') + 1);
    if (($prov['sha256'][$short] ?? null) !== $sha) {
        $fail("runtime hash mismatch for $short");
    }
}
$streams = ['OFF12', 'OFF100', 'SPR_S', 'SPR_R', 'BRU_S', 'BRU_R', 'UAS', 'UAC'];
foreach ($streams as $s) {
    foreach (HMCI_MODES as $m) {
        if (! isset($cells[$s][$m])) {
            $fail("missing cell $s/$m");
        }
    }
}

$v = static fn(string $s, string $m, string $k): mixed => $cells[$s][$m][$k];
$obs = [
    ['O1', 'Shared NAT larger than the 50-member IP device cap is already mostly denied with no browser id (M0, K1 spray)',
        $v('OFF100', 'M0', 'usersDenied') >= 80, "OFF100 M0 usersDenied={$v('OFF100', 'M0', 'usersDenied')}/100"],
    ['O2', 'A per-browser id in clientFingerprint (direction A) does not reduce NAT collateral; it adds K2 churn denials',
        $v('OFF100', 'M1', 'usersDenied') > $v('OFF100', 'M0', 'usersDenied') && $v('OFF12', 'M1', 'usersDenied') > $v('OFF12', 'M0', 'usersDenied'),
        "OFF12 M0→M1 {$v('OFF12', 'M0', 'usersDenied')}→{$v('OFF12', 'M1', 'usersDenied')}; OFF100 {$v('OFF100', 'M0', 'usersDenied')}→{$v('OFF100', 'M1', 'usersDenied')}"],
    ['O3', 'sessionDeviceId without trust (M2) behaves exactly like M1 for decisions: the id enters the composite DeviceFP',
        $v('OFF100', 'M2', 'check') === $v('OFF100', 'M1', 'check') && $v('SPR_R', 'M2', 'check') === $v('SPR_R', 'M1', 'check'),
        'OFF100 and SPR_R check strings identical M1 vs M2'],
    ['O4', 'Pre-authentication trust on a host cookie (M3) gives a stable sprayer full verifier reach (K1 advisory for trusted)',
        $v('SPR_S', 'M3', 'reach') === 200 && $v('SPR_S', 'M0', 'reach') < 10, "SPR_S reach M0={$v('SPR_S', 'M0', 'reach')} M3={$v('SPR_S', 'M3', 'reach')}/200"],
    ['O5', 'Pre-authentication trust (M3) does not relieve the benign NAT: K2 churn still denies trusted browsers',
        $v('OFF100', 'M3', 'usersDenied') >= $v('OFF100', 'M0', 'usersDenied'), "OFF100 M3 usersDenied={$v('OFF100', 'M3', 'usersDenied')}"],
    ['O6', 'Pre-authentication trust (M3) widens per-account brute-force reach (known-device classification, DEC-021 bound applies)',
        $v('BRU_S', 'M3', 'reach') > $v('BRU_S', 'M0', 'reach'), "BRU_S reach M0={$v('BRU_S', 'M0', 'reach')} M3={$v('BRU_S', 'M3', 'reach')}/100"],
    ['O7', 'correlationId = browser id (M4) removes credential-spray protection for a stable-id sprayer',
        $v('SPR_S', 'M4', 'reach') === 200, "SPR_S M4 reach={$v('SPR_S', 'M4', 'reach')}/200"],
    ['O8', 'correlationId = browser id (M4) gives the benign NAT nothing (spray subjects are still distinct per browser)',
        $v('OFF100', 'M4', 'check') === $v('OFF100', 'M0', 'check'), 'OFF100 check strings identical M0 vs M4'],
    ['O9', 'Rotating the id per attempt never gains reach over a stable id in any mode on S0-on-main (churn / K4 still bind)',
        (static function () use ($v): bool {
            foreach (HMCI_MODES as $m) {
                if ($v('SPR_R', $m, 'reach') > max(7, $v('SPR_S', $m, 'reach')) || $v('BRU_R', $m, 'reach') > max(3, $v('BRU_S', $m, 'reach'))) {
                    return false;
                }
            }

            return true;
        })(),
        'SPR_R reach ' . implode('/', array_map(static fn(string $m): int => $v('SPR_R', $m, 'reach'), HMCI_MODES))
        . '; BRU_R reach ' . implode('/', array_map(static fn(string $m): int => $v('BRU_R', $m, 'reach'), HMCI_MODES)) . ' (M0..M4)'],
    ['O10', 'A browser update (UA major) splits a stable id into a new DeviceFP / K5 in every mode; the known owner gains reach',
        (static function () use ($v): bool {
            foreach (HMCI_MODES as $m) {
                if ($v('UAC', $m, 'distinctK5') !== 2 || $v('UAC', $m, 'reach') <= $v('UAS', $m, 'reach')) {
                    return false;
                }
            }

            return true;
        })(),
        "UAS reach={$v('UAS', 'M0', 'reach')} UAC reach={$v('UAC', 'M0', 'reach')} distinctK5={$v('UAC', 'M0', 'distinctK5')} (all modes equal)"],
];

$jsonl = '';
foreach ($obs as [$id, $title, $ok, $evidence]) {
    $jsonl .= json_encode(
        ['id' => $id, 'result' => $ok ? 'REPRODUCED' : 'NOT_REPRODUCED', 'title' => $title, 'evidence' => $evidence],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
}

$md = "# H01 — host-issued browser id through existing inputs, S0-on-main (generated by report.php — do not edit)\n\n";
$md .= "Runtime: `{$prov['level']}`, baseline `{$prov['baseSha']}`, unpatched. Hashes: ";
$md .= implode(', ', array_map(static fn(string $k, string $s): string => "`$k` `" . substr($s, 0, 8) . '…' . substr($s, -4) . '`', array_keys($prov['sha256']), $prov['sha256'])) . ".\n\n";
$md .= "Modes: " . implode('; ', array_map(static fn(string $m): string => "**$m** " . HMCI_MODE_TITLE[$m], HMCI_MODES)) . ".\n\n";
$md .= "## Benign shared NAT (Login, `pre+out`) — users denied at least once at `checkOnly` / denied checks / attempts\n\n";
$md .= "| Stream | " . implode(' | ', HMCI_MODES) . " |\n|---|" . str_repeat('---|', count(HMCI_MODES)) . "\n";
foreach (['OFF12', 'OFF100'] as $s) {
    $md .= "| $s | " . implode(' | ', array_map(static fn(string $m): string => sprintf('%d/%d users · %d/%d checks', $v($s, $m, 'usersDenied'), $v($s, $m, 'users'), $v($s, $m, 'denied'), $v($s, $m, 'attempts')), HMCI_MODES)) . " |\n";
}
$md .= "\nPersisted blocks active at each user's first denied check:\n\n| Stream | " . implode(' | ', HMCI_MODES) . " |\n|---|" . str_repeat('---|', count(HMCI_MODES)) . "\n";
foreach (['OFF12', 'OFF100'] as $s) {
    $md .= "| $s | " . implode(' | ', array_map(static fn(string $m): string => implode(', ', array_map(static fn(string $k, int $c): string => "{$k}×{$c}", array_keys($v($s, $m, 'firstDenialBlockedKeys')), $v($s, $m, 'firstDenialBlockedKeys'))), HMCI_MODES)) . " |\n";
}
$md .= "\n## Attackers and the known owner — verifier reach (`checkOnly` allowed) / attempts\n\n| Stream | " . implode(' | ', HMCI_MODES) . " |\n|---|" . str_repeat('---|', count(HMCI_MODES)) . "\n";
foreach (['SPR_S', 'SPR_R', 'BRU_S', 'BRU_R', 'UAS', 'UAC'] as $s) {
    $md .= "| $s | " . implode(' | ', array_map(static fn(string $m): string => $v($s, $m, 'reach') . '/' . $v($s, $m, 'attempts'), HMCI_MODES)) . " |\n";
}
$md .= "\n## Descriptive observations (facts about the shipped runtime, not gates of a candidate)\n\n| ID | Result | Observation | Evidence |\n|---|---|---|---|\n";
foreach ($obs as [$id, $title, $ok, $evidence]) {
    $md .= "| $id | " . ($ok ? 'REPRODUCED' : '**NOT_REPRODUCED**') . " | $title | $evidence |\n";
}

file_put_contents("$outDir/summary.md", $md);
file_put_contents("$outDir/observations.jsonl", $jsonl);
$bad = array_filter($obs, static fn(array $o): bool => ! $o[2]);
echo 'observations: ' . (count($obs) - count($bad)) . '/' . count($obs) . " reproduced\n";
exit($bad === [] ? 0 : 6);
