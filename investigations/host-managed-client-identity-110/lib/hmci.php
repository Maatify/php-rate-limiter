<?php

declare(strict_types=1);

// #110 Host-Managed Client Instance Identity (HMCI) — isolated S0-on-main observation harness. EVIDENCE ONLY: not part of the package
// runtime, and not a candidate. There is NO patch and NO arm: every cell runs the repository's UNPATCHED EvaluationPipeline at the
// pinned baseline (main after DEC-021, sha-256 asserted). What varies is only HOW a Host could carry a host-issued browser / installation
// identifier through the EXISTING public inputs of RateLimitContextDTO (input modes M0–M4 below).
//
// Reuses, UNCHANGED, the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver + reference stores + controlled clock) from
// investigations/workstream-b-correlation/lib/bootstrap.php. The Lab's ctx() has no correlationId / previously-verified parameters, so
// contexts are built here directly with the public RateLimitContextDTO constructor.
require_once dirname(__DIR__, 2) . '/workstream-b-correlation/lib/bootstrap.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const HMCI_BASELINE_SHA = 'a6c79f45e79171e26031fc720822428e09e6e36c';
const HMCI_RUNTIME_SHA256 = [
    EvaluationPipeline::class => '402139452040cb319208ca7a441b2f70210bf1acee4bd4a0ad666e8101ef6eab',
    DeviceIdentityResolver::class => 'ee654fcfa5d069cef5cfa37950812747aab3801e8f35a430fa132d4dca3c0e6c',
    EphemeralBucket::class => '00531031ebb4e864b5361a0b31332388db56c0d9e5e3c2752cf9756ec8febd8a',
];
/** Candidate / arm labels of earlier studies. This study has none; running under one is a provenance error. */
const HMCI_FOREIGN_LABELS = ['WSB_CANDIDATE', 'OQC_ARM', 'GNH_ARM', 'OAAA_ARM'];

/**
 * Input modes: how a Host could pass a host-issued browser id (`bid`) TODAY, with no package change.
 *
 *   M0  none            no client fingerprint, no session id           (S0 reference: LOW, one shared fingerprint per IP+UA)
 *   M1  fp_bid          clientFingerprint = ['bid' => bid]              (direction A: MEDIUM, bid folded into DeviceFP)
 *   M2  sess_untrusted  sessionDeviceId = bid, isSessionTrusted = false (direction B without trust: LOW, bid folded into DeviceFP)
 *   M3  sess_trusted    sessionDeviceId = bid, isSessionTrusted = true  (direction B with trust BEFORE authentication: contract
 *                                                                       violation of DEVICE_FINGERPRINT §3.3 — measured as a hazard)
 *   M4  corr_bid        correlationId = bid                             (reusing correlationId as the browser id: misuse of §0)
 */
const HMCI_MODES = ['M0', 'M1', 'M2', 'M3', 'M4'];
const HMCI_MODE_TITLE = [
    'M0' => 'none (S0 reference)',
    'M1' => 'clientFingerprint.bid',
    'M2' => 'sessionDeviceId=bid, untrusted',
    'M3' => 'sessionDeviceId=bid, trusted pre-auth (contract violation)',
    'M4' => 'correlationId=bid (misuse)',
];

const FIREFOX_130 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';
const FIREFOX_131 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';
const OFFICE_NAT = '198.51.100.20';
const ATTACK_IP = '192.0.2.77';

/**
 * Proves WHICH runtime ran: the repository's own unpatched files, byte-identical to the pinned hashes. Exit 4 otherwise.
 *
 * @return array<string, mixed>
 */
function hmciProvenance(): array
{
    foreach (HMCI_FOREIGN_LABELS as $env) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "HMCI provenance violation: $env is set ($v); this study has no candidate arm\n");
            exit(4);
        }
    }
    $repo = realpath(dirname(__DIR__, 3));
    $hashes = [];
    foreach (HMCI_RUNTIME_SHA256 as $class => $pinned) {
        $file = (string) (new \ReflectionClass($class))->getFileName();
        $sha = hash_file('sha256', $file);
        $inRepo = $repo !== false && str_starts_with((string) realpath($file), $repo . '/src/');
        if ($sha !== $pinned || ! $inRepo) {
            fwrite(STDERR, "HMCI provenance violation: $class is not the repository's pinned baseline ($sha, $file)\n");
            exit(4);
        }
        $hashes[substr($class, strrpos($class, '\\') + 1)] = $sha;
    }

    return ['level' => 'S0_ON_MAIN_RUNTIME_OBSERVED', 'baseSha' => HMCI_BASELINE_SHA, 'sha256' => $hashes, 'patched' => false];
}

/** Deterministic opaque browser id for actor $who, generation $gen (a rotation increments $gen). */
function bid(string $who, int $gen = 0): string
{
    return 'bid-' . substr(hash('sha256', "hmci|$who|$gen"), 0, 32);
}

function hmciCtx(string $mode, string $ip, string $ua, ?string $account, string $bid, bool $previouslyVerified = false): RateLimitContextDTO
{
    return match ($mode) {
        'M0' => new RateLimitContextDTO($ip, $ua, $account, null, null, false, [], $previouslyVerified, null),
        'M1' => new RateLimitContextDTO($ip, $ua, $account, ['bid' => $bid], null, false, [], $previouslyVerified, null),
        'M2' => new RateLimitContextDTO($ip, $ua, $account, null, $bid, false, [], $previouslyVerified, null),
        'M3' => new RateLimitContextDTO($ip, $ua, $account, null, $bid, true, [], $previouslyVerified, null),
        'M4' => new RateLimitContextDTO($ip, $ua, $account, null, null, false, [], $previouslyVerified, $bid),
    };
}

/** Which canonical keys hold an active persisted block right now (attribution of a denial). */
function hmciBlockedKeys(Lab $lab, RateLimitContextDTO $c): string
{
    $now = $lab->clock->now()->getTimestamp();
    $d = $lab->resolver->resolve($c);
    $p = $lab->policy->getName();
    $pre = $lab->ipPrefix($c->ip);
    $h = static fn(string $s): string => hash_hmac('sha256', $s, $lab->secret);
    $keys = [
        'K1' => $h("{$p}:rate_limiter:k1:v2:prod:{$pre}"),
        'K2' => $h("{$p}:rate_limiter:k2:v2:prod:{$pre}:{$d->normalizedUa}"),
        'K3' => $h("{$p}:rate_limiter:k3:v2:prod:{$pre}:{$d->fingerprintHash}"),
        'K4' => $c->accountId !== null ? $h("{$p}:rate_limiter:k4:v2:prod:{$c->accountId}") : null,
        'K5' => $c->accountId !== null ? $h("{$p}:rate_limiter:k5:v2:prod:{$c->accountId}:{$d->fingerprintHash}") : null,
    ];
    $out = [];
    foreach ($keys as $name => $key) {
        $b = $key === null ? null : $lab->store->checkBlock($key);
        if ($b !== null && $b->level >= 2 && $b->expiresAt > $now) {
            $out[] = $name;
        }
    }

    return $out === [] ? '-' : implode('+', $out);
}

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

/**
 * The H01 matrix definition shared by the scenario and the report: stream => [class, shape]. For benign streams the shape is
 * [browsers, spacing] of officeEvents(); otherwise it is the fixed attempt count.
 */
const HMCI_H01_STREAMS = [
    'OFF12' => ['benign', [12, 30]],
    'OFF100' => ['benign', [100, 18]],
    'SPR_S' => ['attack', 200],
    'SPR_R' => ['attack', 200],
    'BRU_S' => ['attack', 100],
    'BRU_R' => ['attack', 100],
    'UAS' => ['owner', 12],
    'UAC' => ['owner', 12],
];
/** Confidence the default resolver must report for each input mode (benign and attack cells carry it). */
const HMCI_MODE_CONFIDENCE = ['M0' => 'LOW', 'M1' => 'MEDIUM', 'M2' => 'LOW', 'M3' => 'HIGH', 'M4' => 'LOW'];

/** H02 bootstrap model definition shared by the scenario and the report (see scenarios/h02_bootstrap.php). */
const H02_DAYS = 14;
const H02_BROWSERS = 100;
/** Attacker attempts per day in the ATK / ATKM variants: one every 60 s from 08:50 to 10:00 inclusive. */
const H02_ATTACKS_PER_DAY = 71;
const H02_VARIANTS = [
    'MAIN' => ['enet' => 0, 'clear' => 0, 'ttlDays' => null, 'attack' => false],
    'BASE' => ['enet' => null, 'clear' => 0, 'ttlDays' => null, 'attack' => false],
    'CAP5' => ['enet' => 5, 'clear' => 0, 'ttlDays' => null, 'attack' => false],
    'CLR10' => ['enet' => null, 'clear' => 10, 'ttlDays' => null, 'attack' => false],
    'FIX7' => ['enet' => null, 'clear' => 0, 'ttlDays' => 7, 'attack' => false],
    'ATK' => ['enet' => null, 'clear' => 0, 'ttlDays' => null, 'attack' => true],
    'ATKM' => ['enet' => 0, 'clear' => 0, 'ttlDays' => null, 'attack' => true],
];
