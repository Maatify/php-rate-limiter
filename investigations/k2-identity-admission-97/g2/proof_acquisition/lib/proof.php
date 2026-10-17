<?php

declare(strict_types=1);

// G2 proof acquisition (#97) — proof model. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Host model (PLAN.md §2): stateless MAC'd challenges and Ed25519 assertions (case A), out-of-band links delivered to a
// registered mailbox (case B), and a proof minter. The boundary (G2Boundary) stands for the library-side check that G1's P1
// requires: it puts a request in P only for an integrity-protected proof token bound to this tenant, account, operation,
// device and revocation epoch. Every step of the proof path is charged to its own fixed-window scopes, never to U.

/** Test values (PLAN.md §3). Never production thresholds. */
const G2_VALUES = [
    'W' => 600, 'T_ch' => 120, 'CH_src' => 120, 'CH_acct' => 3, 'PV_src' => 60, 'PV_glob' => 0, 'W_iss' => 900, 'ISS_acct' => 3,
    'ISS_src' => 30, 'ISS_glob' => 0, 'T_valid' => 1020, 'T_proof' => 1800, 'RD_acct' => 5,
];

/** Operation policy (PLAN.md §2.1): accepted proof kinds and minimum assurance. */
const G2_OPS = [
    'login' => ['kinds' => ['key', 'link'], 'min' => 1],
    'otp' => ['kinds' => ['key'], 'min' => 2],
    'recovery' => ['kinds' => ['key', 'link'], 'min' => 1],
];

const G2_ASSURANCE = ['key' => 2, 'link' => 1];

const G2_TENANTS = ['tenant-a' => 'a.example', 'tenant-b' => 'b.example'];

/**
 * Arm flags (PLAN.md §2.4).
 *
 * @return array{proof: string, acctCap: bool, single: bool, chargeU: bool, bound: bool, short: bool, sharedOp: bool,
 *     inval: bool, bind: string, T_valid: int}
 */
function g2Arm(string $arm): array
{
    $base = ['proof' => 'none', 'acctCap' => false, 'single' => false, 'chargeU' => false, 'bound' => false, 'short' => false,
        'sharedOp' => false, 'inval' => false, 'bind' => 'full', 'T_valid' => G2_VALUES['T_valid']];

    return match ($arm) {
        'NOPROOF' => $base,
        'PA' => ['proof' => 'A'] + $base,
        'PB' => ['proof' => 'B'] + $base,
        'N-A-ACCTCAP' => ['proof' => 'A', 'acctCap' => true] + $base,
        'N-A-SINGLE' => ['proof' => 'A', 'single' => true] + $base,
        'N-U' => ['proof' => 'A', 'chargeU' => true] + $base,
        'N-B-BOUND' => ['proof' => 'B', 'bound' => true] + $base,
        'N-B-SHORT' => ['proof' => 'B', 'short' => true] + $base,
        'N-B-TTL' => ['proof' => 'B', 'T_valid' => 300] + $base,
        'N-B-SHAREDOP' => ['proof' => 'B', 'sharedOp' => true] + $base,
        'N-B-INVAL' => ['proof' => 'B', 'inval' => true] + $base,
        'N-BIND-A' => ['proof' => 'A', 'bind' => 'weak'] + $base,
        'N-BIND-B' => ['proof' => 'B', 'bind' => 'weak'] + $base,
        default => throw new \InvalidArgumentException("unknown arm $arm"),
    };
}

/**
 * A fresh Ed25519 key pair.
 *
 * @return array{0: non-empty-string, 1: non-empty-string} [secret key, public key]
 */
function g2Keypair(): array
{
    $kp = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
}

function g2B64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function g2Unb64(string $s): ?string
{
    $raw = base64_decode(strtr($s, '-_', '+/'), true);

    return $raw === false ? null : $raw;
}

/** Atomic primitives of the proof path. One reference implementation here; the Redis one is in redis.php. */
interface G2PathStore
{
    /**
     * All-or-nothing fixed-window reservation. Returns the name of the first refused scope, or null when every scope was
     * incremented.
     *
     * @param list<array{name: string, key: string, cap: int, ttl: int}> $defs
     */
    public function reserve(array $defs, int $now): ?string;

    /** Single use: 'ok' the first time a nonce is consumed before $exp, 'used' afterwards. */
    public function consumeNonce(string $nonce, int $exp, int $now): string;

    /**
     * Issuance: reserve $defs and, only if every scope has headroom, store the link record. Returns the refused scope or null.
     *
     * @param list<array{name: string, key: string, cap: int, ttl: int}> $defs
     * @param array{tenant: string, account: string, op: string, exp: int, device: string} $rec
     */
    public function issue(array $defs, string $linkKey, array $rec, int $now): ?string;

    /**
     * ISSUED -> REDEEMED once. Returns ok | unknown | used | revoked | expired | mismatch.
     *
     * @param array{tenant: string, account: string, op: string, device: string} $expect
     */
    public function redeem(string $linkKey, array $expect, int $now): string;

    /** Marks every other ISSUED link of a (tenant, account, op) as REVOKED (arm N-B-INVAL). */
    public function revokeLinks(string $tenant, string $account, string $op, string $exceptKey): void;
}

final class G2RefPathStore implements G2PathStore
{
    /** @var array<string, array{0: int, 1: int}> key => [value, expiresAt] */
    public array $c = [];
    /** @var array<string, int> nonce => expiresAt */
    public array $nonces = [];
    /** @var array<string, array{tenant: string, account: string, op: string, exp: int, device: string, state: string}> */
    public array $links = [];
    public int $writes = 0;

    private function get(string $k, int $now): int
    {
        return isset($this->c[$k]) && $this->c[$k][1] > $now ? $this->c[$k][0] : 0;
    }

    public function reserve(array $defs, int $now): ?string
    {
        foreach ($defs as $d) {
            if ($this->get($d['key'], $now) >= $d['cap']) {
                return $d['name'];
            }
        }
        foreach ($defs as $d) {
            if (! isset($this->c[$d['key']]) || $this->c[$d['key']][1] <= $now) {
                $this->c[$d['key']] = [0, $now + $d['ttl']];
            }
            $this->c[$d['key']][0]++;
            $this->writes++;
        }

        return null;
    }

    public function consumeNonce(string $nonce, int $exp, int $now): string
    {
        if (isset($this->nonces[$nonce]) && $this->nonces[$nonce] > $now) {
            return 'used';
        }
        $this->nonces[$nonce] = $exp;
        $this->writes++;

        return 'ok';
    }

    public function issue(array $defs, string $linkKey, array $rec, int $now): ?string
    {
        $refused = $this->reserve($defs, $now);
        if ($refused !== null) {
            return $refused;
        }
        $this->links[$linkKey] = $rec + ['state' => 'ISSUED'];
        $this->writes++;

        return null;
    }

    public function redeem(string $linkKey, array $expect, int $now): string
    {
        $r = $this->links[$linkKey] ?? null;
        if ($r === null) {
            return 'unknown';
        }
        if ($r['state'] === 'REDEEMED') {
            return 'used';
        }
        if ($r['state'] === 'REVOKED') {
            return 'revoked';
        }
        if ($r['exp'] <= $now) {
            return 'expired';
        }
        if ($r['tenant'] !== $expect['tenant'] || $r['account'] !== $expect['account'] || $r['op'] !== $expect['op']
            || ($r['device'] !== '' && $r['device'] !== $expect['device'])) {
            return 'mismatch';
        }
        $this->links[$linkKey]['state'] = 'REDEEMED';
        $this->writes++;

        return 'ok';
    }

    public function revokeLinks(string $tenant, string $account, string $op, string $exceptKey): void
    {
        foreach ($this->links as $k => $r) {
            if ($k !== $exceptKey && $r['tenant'] === $tenant && $r['account'] === $account && $r['op'] === $op && $r['state'] === 'ISSUED') {
                $this->links[$k]['state'] = 'REVOKED';
                $this->writes++;
            }
        }
    }
}

/**
 * Host model. Holds the registered credentials and mailboxes, mints challenges, links and proof tokens. The parties are
 * labels for accounting only ('owner', 'attacker'); no decision reads them.
 */
final class G2Host
{
    public string $kCh;
    public string $kProof;
    /** @var array<string, array<string, array<string, array{pk: non-empty-string, revoked: bool}>>> tenant => account => credId => cred */
    public array $creds = [];
    /** @var array<string, int> "tenant|account" => revocation epoch */
    public array $epoch = [];
    /** @var array<string, true> revoked proof-token ids */
    public array $revokedJti = [];
    /** @var array<string, list<array{token: string, op: string, iat: int, exp: int, requester: string}>> "tenant|account" => mailbox */
    public array $mailbox = [];
    /** @var array<string, string> "tenant|account|op" => the only valid nonce (arm N-A-SINGLE) */
    public array $latestNonce = [];
    /** @var array<string, array<string, int>> party => counter => value */
    public array $stats = [];
    /** @var list<array{t: int, party: string, step: string, ok: bool, why: string}> */
    public array $log = [];

    /**
     * @param array{proof: string, acctCap: bool, single: bool, chargeU: bool, bound: bool, short: bool, sharedOp: bool,
     *     inval: bool, bind: string, T_valid: int} $arm
     * @param array<string, int> $v
     * @param ?\Closure(string, string, int): bool $chargeU arm N-U: reserves U_acct + U_src for (source, subject) at time t
     */
    public function __construct(
        public array $arm,
        public G2PathStore $store,
        public array $v = G2_VALUES,
        public ?\Closure $chargeU = null,
    ) {
        $this->kCh = random_bytes(32);
        $this->kProof = random_bytes(32);
    }

    private function bump(string $party, string $what, int $n = 1): void
    {
        $this->stats[$party][$what] = ($this->stats[$party][$what] ?? 0) + $n;
    }

    /**
     * @param array{ok: bool, why: string} $r
     * @return array{ok: bool, why: string}
     */
    private function note(int $t, string $party, string $step, array $r): array
    {
        $this->log[] = ['t' => $t, 'party' => $party, 'step' => $step, 'ok' => $r['ok'], 'why' => $r['why']];
        $this->bump($party, $step . ($r['ok'] ? ':ok' : ':refused'));

        return $r;
    }

    /** @return array{name: string, key: string, cap: int, ttl: int} */
    private function scope(string $name, string $value, int $window, int $cap, int $now): array
    {
        return ['name' => $name, 'key' => "g2:$name:" . hash('sha256', $value) . ':' . intdiv($now, $window), 'cap' => $cap, 'ttl' => 2 * $window];
    }

    /**
     * Registers an Ed25519 credential.
     *
     * @return array{0: non-empty-string, 1: string} [secret key, credential id]
     */
    public function register(string $tenant, string $account): array
    {
        [$sk, $pk] = g2Keypair();
        $credId = bin2hex(random_bytes(8));
        $this->creds[$tenant][$account][$credId] = ['pk' => $pk, 'revoked' => false];
        $this->epoch["$tenant|$account"] ??= 0;

        return [$sk, $credId];
    }

    public function revokeCredential(string $tenant, string $account, string $credId): void
    {
        $this->creds[$tenant][$account][$credId]['revoked'] = true;
        $this->epoch["$tenant|$account"] = ($this->epoch["$tenant|$account"] ?? 0) + 1;
    }

    // ---- case A ------------------------------------------------------------------------------------------------------------

    /** @return array{ok: bool, why: string, challenge: ?string} */
    public function challenge(int $t, string $party, string $src, string $tenant, string $account, string $op): array
    {
        $defs = [$this->scope('ch_src', $src, $this->v['W'], $this->v['CH_src'], $t)];
        if ($this->arm['acctCap']) {
            $defs[] = $this->scope('ch_acct', "$tenant|$account", $this->v['W'], $this->v['CH_acct'], $t);
        }
        $refused = $this->store->reserve($defs, $t);
        if ($refused !== null) {
            return $this->note($t, $party, 'challenge', ['ok' => false, 'why' => "cap:$refused"]) + ['challenge' => null];
        }
        $nonce = bin2hex(random_bytes(16));
        $payload = (string) json_encode(['v' => 1, 't' => $tenant, 'a' => $account, 'o' => $op, 'n' => $nonce, 'e' => $t + $this->v['T_ch']]);
        if ($this->arm['single']) {
            $this->latestNonce["$tenant|$account|$op"] = $nonce;
        }

        return $this->note($t, $party, 'challenge', ['ok' => true, 'why' => '-'])
            + ['challenge' => g2B64($payload) . '.' . g2B64(hash_hmac('sha256', $payload, $this->kCh, true))];
    }

    /** What an authenticator signs (Ed25519 stands for the WebAuthn assertion signature only). */
    public static function signedMessage(string $tenant, string $challenge): string
    {
        return G2_TENANTS[$tenant] . '|' . $challenge . '|g2.get';
    }

    /**
     * Verifies an assertion and mints a key proof bound to $device.
     *
     * @return array{ok: bool, why: string, token: ?string}
     */
    public function assert(int $t, string $party, string $src, string $tenant, string $account, string $op, string $challenge, string $credId, string $sig, string $device): array
    {
        $fail = fn(string $why): array => $this->note($t, $party, 'assert', ['ok' => false, 'why' => $why]) + ['token' => null];
        if ($this->arm['chargeU']) {
            if ($this->chargeU === null || ! ($this->chargeU)($src, "$tenant|$account", $t)) {
                return $fail('cap:U');
            }
        } else {
            $defs = [$this->scope('pv_src', $src, $this->v['W'], $this->v['PV_src'], $t)];
            if ($this->v['PV_glob'] > 0) {
                $defs[] = $this->scope('pv_glob', 'all', $this->v['W'], $this->v['PV_glob'], $t);
            }
            $refused = $this->store->reserve($defs, $t);
            if ($refused !== null) {
                return $fail("cap:$refused");
            }
        }
        $parts = explode('.', $challenge);
        $payload = count($parts) === 2 ? g2Unb64($parts[0]) : null;
        $mac = count($parts) === 2 ? g2Unb64($parts[1]) : null;
        if ($payload === null || $mac === null || ! hash_equals(hash_hmac('sha256', $payload, $this->kCh, true), $mac)) {
            return $fail('challenge-mac');
        }
        /** @var array{v: int, t: string, a: string, o: string, n: string, e: int} $ch */
        $ch = json_decode($payload, true);
        if ($ch['e'] <= $t) {
            return $fail('challenge-expired');
        }
        if ($ch['t'] !== $tenant || $ch['a'] !== $account || $ch['o'] !== $op) {
            return $fail('challenge-binding');
        }
        if ($this->arm['single'] && ($this->latestNonce["$tenant|$account|$op"] ?? '') !== $ch['n']) {
            return $fail('challenge-superseded');
        }
        $cred = $this->creds[$tenant][$account][$credId] ?? null;
        if ($cred === null) {
            return $fail('credential-unknown');
        }
        if ($cred['revoked']) {
            return $fail('credential-revoked');
        }
        if ($device === '') {
            return $fail('no-device');
        }
        $this->bump($party, 'sigVerify');
        if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || ! sodium_crypto_sign_verify_detached($sig, self::signedMessage($tenant, $challenge), $cred['pk'])) {
            return $fail('signature');
        }
        if ($this->store->consumeNonce($ch['n'], $ch['e'], $t) !== 'ok') {
            return $fail('challenge-replayed');
        }
        $this->note($t, $party, 'assert', ['ok' => true, 'why' => '-']);

        return ['ok' => true, 'why' => '-', 'token' => $this->mint($t, $party, $tenant, $account, $op, 'key', $device)];
    }

    // ---- case B ------------------------------------------------------------------------------------------------------------

    private function linkKey(string $tenant, string $account, string $token): string
    {
        // short codes are only unique per account; long tokens are global
        return $this->arm['short'] ? hash('sha256', "$tenant|$account|$token") : hash('sha256', "$tenant|$token");
    }

    /** @return array{ok: bool, why: string} */
    public function issue(int $t, string $party, string $src, string $tenant, string $account, string $op, string $requesterDevice): array
    {
        if (! in_array($op, ['login', 'recovery'], true)) {
            return $this->note($t, $party, 'issue', ['ok' => false, 'why' => 'op-not-linkable']);
        }
        $W = $this->v['W_iss'];
        $defs = [
            $this->scope('iss_acct', $this->arm['sharedOp'] ? "$tenant|$account" : "$tenant|$account|$op", $W, $this->v['ISS_acct'], $t),
            $this->scope('iss_src', $src, $W, $this->v['ISS_src'], $t),
        ];
        if ($this->v['ISS_glob'] > 0) {
            $defs[] = $this->scope('iss_glob', 'all', $W, $this->v['ISS_glob'], $t);
        }
        $token = $this->arm['short'] ? sprintf('%06d', mt_rand(0, 999999)) : bin2hex(random_bytes(32));
        $exp = $t + $this->arm['T_valid'];
        $key = $this->linkKey($tenant, $account, $token);
        $refused = $this->store->issue(
            $defs,
            $key,
            ['tenant' => $tenant, 'account' => $account, 'op' => $op, 'exp' => $exp, 'device' => $this->arm['bound'] ? $requesterDevice : ''],
            $t,
        );
        if ($refused !== null) {
            return $this->note($t, $party, 'issue', ['ok' => false, 'why' => "cap:$refused"]);
        }
        if ($this->arm['inval']) {
            $this->store->revokeLinks($tenant, $account, $op, $key);
        }
        // delivered to the account's registered channel, never to the requester
        $this->mailbox["$tenant|$account"][] = ['token' => $token, 'op' => $op, 'iat' => $t, 'exp' => $exp, 'requester' => $requesterDevice];
        $this->bump($account, 'messagesDelivered');

        return $this->note($t, $party, 'issue', ['ok' => true, 'why' => '-']);
    }

    /** @return array{ok: bool, why: string, token: ?string} */
    public function redeem(int $t, string $party, string $src, string $tenant, string $account, string $op, string $link, string $device): array
    {
        $fail = fn(string $why): array => $this->note($t, $party, 'redeem', ['ok' => false, 'why' => $why]) + ['token' => null];
        $defs = [$this->scope('pv_src', $src, $this->v['W'], $this->v['PV_src'], $t)];
        if ($this->v['PV_glob'] > 0) {
            $defs[] = $this->scope('pv_glob', 'all', $this->v['W'], $this->v['PV_glob'], $t);
        }
        if ($this->arm['short']) {
            // guessable codes need a per-account redemption cap: the circular lever of PLAN.md §0
            $defs[] = $this->scope('rd_acct', "$tenant|$account", $this->v['W'], $this->v['RD_acct'], $t);
        }
        $refused = $this->store->reserve($defs, $t);
        if ($refused !== null) {
            return $fail("cap:$refused");
        }
        if ($device === '') {
            return $fail('no-device');
        }
        $this->bump($party, 'redeemLookup');
        $key = $this->linkKey($tenant, $account, $link);
        $res = $this->store->redeem($key, ['tenant' => $tenant, 'account' => $account, 'op' => $op, 'device' => $device], $t);
        if ($res !== 'ok') {
            return $fail("link-$res");
        }
        $this->note($t, $party, 'redeem', ['ok' => true, 'why' => '-']);

        return ['ok' => true, 'why' => '-', 'token' => $this->mint($t, $party, $tenant, $account, $op, 'link', $device)];
    }

    // ---- proof tokens ------------------------------------------------------------------------------------------------------

    /** Host minting. Exposed for C probes that model a Host bug (e.g. a link proof minted for otp). */
    public function mint(int $t, string $party, string $tenant, string $account, string $op, string $kind, string $device, ?int $assurance = null, ?int $ttl = null): string
    {
        $this->bump($party, 'proofsMinted');
        $payload = (string) json_encode(['v' => 1, 't' => $tenant, 'a' => $account, 'o' => $op, 'k' => $kind,
            'as' => $assurance ?? G2_ASSURANCE[$kind], 'd' => hash('sha256', $device), 'iat' => $t, 'exp' => $t + ($ttl ?? $this->v['T_proof']),
            'j' => bin2hex(random_bytes(12)), 'ep' => $this->epoch["$tenant|$account"] ?? 0]);

        return g2B64($payload) . '.' . g2B64(hash_hmac('sha256', $payload, $this->kProof, true));
    }

    /** Newest unexpired link for (tenant, account, op) in the mailbox, preferring one this device requested. */
    public function readMailbox(int $t, string $tenant, string $account, string $op, string $device): ?string
    {
        $best = null;
        $own = null;
        foreach ($this->mailbox["$tenant|$account"] ?? [] as $m) {
            if ($m['op'] !== $op || $m['exp'] <= $t) {
                continue;
            }
            $best = $m['token'];
            if ($m['requester'] === $device) {
                $own = $m['token'];
            }
        }

        return $own ?? $best;
    }
}

/**
 * The modelled boundary (PLAN.md §2.1). Lane P iff the presented proof token is intact and bound to this request. A request
 * carries no lane field: claims, client ids and Host trust flags have no input here.
 */
final class G2Boundary
{
    public function __construct(public G2Host $host, public string $mode = 'full') {}

    /** @return array{lane: string, why: string, jti: ?string} */
    public function lane(int $t, string $tenant, string $account, string $op, string $device, ?string $token): array
    {
        $u = static fn(string $why): array => ['lane' => 'U', 'why' => $why, 'jti' => null];
        if ($token === null || $token === '') {
            return $u('no-proof');
        }
        $parts = explode('.', $token);
        $payload = count($parts) === 2 ? g2Unb64($parts[0]) : null;
        $mac = count($parts) === 2 ? g2Unb64($parts[1]) : null;
        if ($payload === null || $mac === null || ! hash_equals(hash_hmac('sha256', $payload, $this->host->kProof, true), $mac)) {
            return $u('mac');
        }
        $p = json_decode($payload, true);
        if (! is_array($p) || ($p['v'] ?? null) !== 1) {
            return $u('version');
        }
        if (! is_int($p['exp'] ?? null) || $p['exp'] <= $t) {
            return $u('expired');
        }
        if (! is_int($p['iat'] ?? null) || $p['iat'] > $t) {
            return $u('not-yet-valid');
        }
        if ($this->mode === 'full') {
            if ($p['t'] !== $tenant) {
                return $u('tenant');
            }
            if ($p['a'] !== $account) {
                return $u('account');
            }
            if ($p['o'] !== $op) {
                return $u('operation');
            }
            $pol = G2_OPS[$op] ?? null;
            if ($pol === null || ! in_array($p['k'], $pol['kinds'], true) || $p['as'] < $pol['min']) {
                return $u('assurance');
            }
            if ($p['ep'] !== ($this->host->epoch["$tenant|$account"] ?? 0)) {
                return $u('revoked-epoch');
            }
            if (isset($this->host->revokedJti[$p['j']])) {
                return $u('revoked-jti');
            }
            if ($device === '' || ! hash_equals((string) $p['d'], hash('sha256', $device))) {
                return $u('device');
            }
        }

        return ['lane' => 'P', 'why' => '-', 'jti' => (string) $p['j']];
    }
}
