<?php

declare(strict_types=1);

// G2 section C (PLAN.md §5 C): every probe must be refused inside the modelled boundary or the Host acquisition step.
// accepted = the probe produced lane P (token probes) or a minted proof (acquisition probes).

require dirname(__DIR__) . '/lib/sim.php';

const C_T = 'tenant-a';
const C_ACCT = 'acct-v';
const C_DEV = 'owner-dev';

/**
 * Host, boundary, owner secret key, credential id and a valid owner proof token.
 *
 * @return array{0: G2Host, 1: G2Boundary, 2: non-empty-string, 3: string, 4: string}
 */
function cSetup(string $armName): array
{
    $arm = g2Arm($armName);
    $host = new G2Host($arm, new G2RefPathStore());
    $bd = new G2Boundary($host, $arm['bind']);
    [$sk, $cred] = $host->register(C_T, C_ACCT);
    $host->register(C_T, 'acct-w');
    $host->register('tenant-b', C_ACCT);
    if ($arm['proof'] === 'A') {
        $c = (string) $host->challenge(100, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login')['challenge'];
        $tok = $host->assert(101, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $c, $cred, sodium_crypto_sign_detached(G2Host::signedMessage(C_T, $c), $sk), C_DEV)['token'];
    } else {
        $host->issue(100, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', C_DEV);
        $link = (string) $host->readMailbox(101, C_T, C_ACCT, 'login', C_DEV);
        $tok = $host->redeem(102, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $link, C_DEV)['token'];
    }
    if ($tok === null || $bd->lane(103, C_T, C_ACCT, 'login', C_DEV, $tok)['lane'] !== 'P') {
        fwrite(STDERR, "C setup: the valid owner proof is not accepted in $armName\n");
        exit(3);
    }

    return [$host, $bd, $sk, $cred, $tok];
}

/** @param non-empty-string $key */
function cSign(string $challenge, string $key): string
{
    return sodium_crypto_sign_detached(G2Host::signedMessage(C_T, $challenge), $key);
}

/** @return array<string, array{accepted: bool, why: string}> */
function cProbes(string $armName): array
{
    $out = [];
    $lane = static function (string $name, G2Boundary $bd, int $t, string $tenant, string $acct, string $op, string $dev, ?string $tok) use (&$out): void {
        $r = $bd->lane($t, $tenant, $acct, $op, $dev, $tok);
        $out[$name] = ['accepted' => $r['lane'] === 'P', 'why' => $r['why']];
    };
    $acq = static function (string $name, array $r) use (&$out): void {
        $out[$name] = ['accepted' => ($r['token'] ?? null) !== null, 'why' => $r['why']];
    };

    // ---- proof-token probes (fresh setup per probe where state changes) ----
    [$h, $bd, , $cred, $tok] = cSetup($armName);
    $lane('missing', $bd, 110, C_T, C_ACCT, 'login', C_DEV, null);
    $lane('forged:random', $bd, 110, C_T, C_ACCT, 'login', C_DEV, g2B64(random_bytes(64)) . '.' . g2B64(random_bytes(32)));
    [, , , , $foreign] = cSetup($armName);
    $lane('forged:other-minting-key', $bd, 110, C_T, C_ACCT, 'login', C_DEV, $foreign);
    [$p64, $m64] = explode('.', $tok);
    $payload = (array) json_decode((string) g2Unb64($p64), true);
    $lane('forged:tampered-field', $bd, 110, C_T, 'acct-w', 'login', C_DEV, g2B64((string) json_encode(['a' => 'acct-w'] + $payload)) . '.' . $m64);
    $lane('expired', $bd, 101 + G2_VALUES['T_proof'] + 5, C_T, C_ACCT, 'login', C_DEV, $tok);
    $lane('replay:other-device', $bd, 110, C_T, C_ACCT, 'login', 'attacker-dev', $tok);
    $lane('replay:no-device', $bd, 110, C_T, C_ACCT, 'login', '', $tok);
    $lane('other-account', $bd, 110, C_T, 'acct-w', 'login', C_DEV, $tok);
    $lane('other-operation', $bd, 110, C_T, C_ACCT, 'otp', C_DEV, $tok);
    $lane('other-tenant', $bd, 110, 'tenant-b', C_ACCT, 'login', C_DEV, $tok);
    $lane('assurance:link-minted-for-otp', $bd, 110, C_T, C_ACCT, 'otp', C_DEV, $h->mint(110, 'host-bug', C_T, C_ACCT, 'otp', 'link', C_DEV));
    $lane('assurance:level-0-minted-for-login', $bd, 110, C_T, C_ACCT, 'login', C_DEV, $h->mint(110, 'host-bug', C_T, C_ACCT, 'login', 'key', C_DEV, 0));
    $lane('claim:lane-P-without-proof', $bd, 110, C_T, C_ACCT, 'login', C_DEV, 'P');
    $lane('claim:client-instance-id-only', $bd, 110, C_T, C_ACCT, 'login', C_DEV, 'cid-0f3a9c');
    $lane('claim:unverified-host-trust-flag', $bd, 110, C_T, C_ACCT, 'login', C_DEV, 'isSessionTrusted=true');
    [$h2, $bd2, , $cred2, $tok2] = cSetup($armName);
    $h2->revokedJti[(string) json_decode((string) g2Unb64(explode('.', $tok2)[0]), true)['j']] = true;
    $lane('revoked:jti', $bd2, 110, C_T, C_ACCT, 'login', C_DEV, $tok2);
    [$h3, $bd3, , $cred3, $tok3] = cSetup($armName);
    $h3->revokeCredential(C_T, C_ACCT, $cred3);
    $lane('revoked:credential-epoch', $bd3, 110, C_T, C_ACCT, 'login', C_DEV, $tok3);

    // ---- acquisition probes ----
    $arm = g2Arm($armName);
    if ($arm['proof'] === 'A') {
        [$h, , $sk, $cred] = cSetup($armName);
        $sign = cSign(...);
        $cw = (string) $h->challenge(200, 'attacker', '198.19.0.9', C_T, 'acct-w', 'login')['challenge'];
        $acq('A:challenge-for-other-account', $h->assert(201, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $cw, $cred, $sign($cw, $sk), 'd1'));
        $cb = (string) $h->challenge(200, 'attacker', '198.19.0.9', 'tenant-b', C_ACCT, 'login')['challenge'];
        $acq('A:challenge-from-other-tenant', $h->assert(201, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $cb, $cred, $sign($cb, $sk), 'd1'));
        $ce = (string) $h->challenge(200, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login')['challenge'];
        $acq('A:expired-challenge', $h->assert(200 + G2_VALUES['T_ch'] + 1, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $ce, $cred, $sign($ce, $sk), 'd1'));
        $co = (string) $h->challenge(200, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'otp')['challenge'];
        $acq('A:challenge-for-other-operation', $h->assert(201, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $co, $cred, $sign($co, $sk), 'd1'));
        $cr = (string) $h->challenge(200, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login')['challenge'];
        $h->assert(201, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $cr, $cred, $sign($cr, $sk), 'd1');
        $acq('A:replayed-assertion', $h->assert(202, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $cr, $cred, $sign($cr, $sk), 'd2'));
        $cf = (string) $h->challenge(200, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login')['challenge'];
        [$cfp, $cfm] = explode('.', $cf);
        $forged = g2B64((string) json_encode(['e' => 99999] + (array) json_decode((string) g2Unb64($cfp), true))) . '.' . $cfm;
        $acq('A:tampered-challenge', $h->assert(201, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $forged, $cred, $sign($forged, $sk), 'd1'));
        [$atkSk] = g2Keypair();
        $cu = (string) $h->challenge(200, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login')['challenge'];
        $acq('A:unregistered-key', $h->assert(201, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $cu, 'deadbeefdeadbeef', $sign($cu, $atkSk), 'd1'));
        $acq('A:attacker-key-on-owner-credential', $h->assert(201, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $cu, $cred, $sign($cu, $atkSk), 'd1'));
        $cs = (string) $h->challenge(200, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login')['challenge'];
        $acq('A:signature-over-other-challenge', $h->assert(201, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $cs, $cred, $sign($cu, $sk), 'd1'));
        $h->revokeCredential(C_T, C_ACCT, $cred);
        $cv = (string) $h->challenge(200, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login')['challenge'];
        $acq('A:revoked-credential', $h->assert(201, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $cv, $cred, $sign($cv, $sk), 'd1'));
    } else {
        [$h] = cSetup($armName);
        $h->issue(300, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', C_DEV);
        $link = (string) $h->readMailbox(301, C_T, C_ACCT, 'login', C_DEV);
        $acq('B:link-for-other-account', $h->redeem(302, 'attacker', '198.19.0.9', C_T, 'acct-w', 'login', $link, 'd1'));
        $acq('B:link-at-other-tenant', $h->redeem(302, 'attacker', '198.19.0.9', 'tenant-b', C_ACCT, 'login', $link, 'd1'));
        $acq('B:link-for-other-operation', $h->redeem(302, 'attacker', '198.19.0.9', C_T, C_ACCT, 'recovery', $link, 'd1'));
        $acq('B:expired-link', $h->redeem(301 + G2_VALUES['T_valid'] + 1, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $link, 'd1'));
        $h->redeem(303, 'owner', G2_OWNER_SRC, C_T, C_ACCT, 'login', $link, C_DEV);
        $acq('B:replayed-link', $h->redeem(304, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', $link, 'd2'));
        $acq('B:random-link', $h->redeem(304, 'attacker', '198.19.0.9', C_T, C_ACCT, 'login', bin2hex(random_bytes(32)), 'd2'));
        $acq('B:otp-not-linkable', ['token' => null] + $h->issue(305, 'attacker', '198.19.0.9', C_T, C_ACCT, 'otp', 'd2'));
    }
    ksort($out);

    return $out;
}

foreach (['PA', 'PB', 'N-BIND-A', 'N-BIND-B'] as $arm) {
    $probes = cProbes($arm);
    $accepted = array_keys(array_filter($probes, static fn(array $p): bool => $p['accepted']));
    echo json_encode(
        ['s' => 'c', 'arm' => $arm, 'probes' => $probes, 'probeCount' => count($probes), 'accepted' => $accepted],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ), "\n";
}
