<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\KeyPreimageFraming;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Service\NonAccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\NonAccountScopePurpose;
use Maatify\RateLimiter\Service\SimpleFixedWindowKeyDeriver;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Service\SourcePrefix;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-047 (G2) defensive and offensive unit verification for checked framing, non-account `f1` derivation,
 * purpose separation, boundary bounds, E1/simple byte retention, LocalFallback/DeviceIdentity retention proofs,
 * and source guards. Runs on both 32-bit and 64-bit PHP.
 */
#[Group('php32')]
#[Group('g2-non-account-framing')]
final class NonAccountScopeKeyDeriverTest extends TestCase
{
    private const S = 'synthetic-key-secret-not-for-production';
    private const SP = 'synthetic-previous-secret-not-for-production';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertContains(PHP_INT_SIZE, [4, 8]);
    }

    /** @return array<string, array{string, string, string, string, list<string>, string}> */
    public static function independentVectors(): array
    {
        return [
            'K1' => [self::S, 'k1', 'api_heavy_protection', 'prod-eu', ['203.0.113.10'], '22dd5d9bfbebd867e7cf973c5ed744b6299b046b8f1ca121ee88714304cc76f5'],
            'K1 Previous' => [self::SP, 'k1', 'api_heavy_protection', 'prod-eu', ['203.0.113.10'], '5b5764bc1f0475e467d11012ece0c6b86192b0acb9f089aa15ac7b80730a3304'],
            'K2' => [self::S, 'k2', 'api_heavy_protection', 'prod-eu', ['203.0.113.10', 'chrome/126'], '94b81a4dfa457f8c99aa3298d62f56372cfa1c3f549aa4559cd942e8b8bcd79d'],
            'K3' => [self::S, 'k3', 'api_heavy_protection', 'prod-eu', ['203.0.113.10', 'f00dcafe'], '46b53421ddb2d7b8987d7042c039bb15b78456026d0d9d516fe3d2125e9ef5e6'],
            'K1 bytes not chars (colon, NUL, UTF-8)' => [self::S, 'k1', 'a:rate_limiter:k1:v2:b', "prod:eu\x00\u{00FC}", ['203.0.113.10'], '16c4b4066cc992426dacdcbda91568f4d58aa576b530c13780686d6502c2bb26'],
            'device_cap_account scope (separate K4, K1)' => [self::S, 'correlation.device_cap_account.scope', 'login_protection', 'prod-eu', ['e1.9678f6f2458e4fc59a0b91701343b4a1e07c81144d9e40025bb2e9aa2b825fa7', 'deadbeef'], 'e6f16114d40d9f084c41e95d46643cbb1193e19ed73b5789bfeff087adbe8169'],
            'dilution_confirmation (separate scope, window)' => [self::S, 'correlation.dilution_confirmation', 'otp_protection', 'prod-eu', ['cafebabe', '2989656'], '42abfdcb2d88c59df66139499f3ab4586d08f4ed9a2a68f427fb4c4d47c89239'],
            'distributed occurrences window' => [self::S, 'correlation.distributed_account_occurrences.window', 'login_protection', 'prod-eu', ['1793794200'], '9777ab243adf10a6e03824ca829bfd2dd3c1add5dae40f1273166f6bd1941c55'],
            'IPv6 hierarchy scope' => [self::S, 'ipv6_adaptive.hierarchy.scope', 'login_protection', 'prod-eu', ['48', '20010db80001'], '87397d63d25fff15bc4b77599588e2b19bc33776ef40119e7b19437d70804fed'],
            'IPv6 churn anchor' => [self::S, 'ipv6_adaptive.churn_anchor', 'login_protection', 'prod-eu', ['48', 'abcd1234', 'chrome/126'], '6c9a0595fe039e4b85150c235935f099e75ef6f9e437e4f84f343b6b08cc8ff7'],
            'IPv6 spray member' => [self::S, 'ipv6_adaptive.spray.member', 'login_protection', 'prod-eu', ['48', 'acct-0001'], 'f838132799a1340663636ffe92d9d009b3de665d339022b5309afafe6835a611'],
        ];
    }

    /** @param list<string> $semanticFields */
    #[DataProvider('independentVectors')]
    public function testIndependentVectorsReproduceByteForByteOnBothWidths(
        string $secret,
        string $purpose,
        string $policy,
        string $env,
        array $semanticFields,
        string $expected,
    ): void {
        $deriver = new NonAccountScopeKeyDeriver($env);
        $actual = $deriver->derive($purpose, $policy, $secret, ...$semanticFields);

        self::assertSame($expected, $actual);
        self::assertSame($expected, NonAccountKeyOracle::derive($purpose, $policy, $env, $secret, ...$semanticFields));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $actual);
        self::assertStringStartsNotWith('e1.', $actual);
    }

    public function testK1PreimageMatchesHandCheckedHexWireFormat(): void
    {
        $preimage = NonAccountScopeKeyDeriver::preimage(['k1', 'api_heavy_protection', 'prod-eu', '203.0.113.10']);

        self::assertSame(
            '6d6161746966792e726174652d6c696d697465722e6e6f6e2d6163636f756e742e663100'
            . '00000004'
            . '00000002' . '6b31'
            . '00000014' . '6170695f68656176795f70726f74656374696f6e'
            . '00000007' . '70726f642d6575'
            . '0000000c' . '3230332e302e3131332e3130',
            bin2hex($preimage),
        );
    }

    public function testClosedRegistryRejectsUnknownPurposesAndArityMismatchesBeforeFraming(): void
    {
        $deriver = new NonAccountScopeKeyDeriver('prod');
        $invalidCalls = [
            ['unknown.purpose', ['a']],
            ['', ['a']],
            ['k4', ['acct-1']], // E1 account purpose must not be accepted here
            ['k1', []],
            ['k1', ['203.0.113.1', 'extra']],
            ['k2', ['203.0.113.1']],
            ['k3', ['203.0.113.1', 'fp', 'extra']],
            ['correlation.device_cap_account.scope', ['only-one']],
            ['correlation.dilution_confirmation', ['scope-only']],
            ['ipv6_adaptive.hierarchy.scope', ['48']],
            ['ipv6_adaptive.churn_anchor', ['48', 'scope-only']],
        ];

        foreach ($invalidCalls as [$purpose, $fields]) {
            try {
                $deriver->derive($purpose, 'p', self::S, ...$fields);
                self::fail("Expected RateLimiterException for purpose '{$purpose}' with " . count($fields) . ' semantic fields.');
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRecurrenceMatrixAcrossAll33RegisteredPurposesAndOpaqueBoundaryCharacters(): void
    {
        $all = NonAccountScopePurpose::all();
        self::assertCount(33, $all, 'Closed non-account purpose registry must enumerate all 33 registered roles.');

        $corpus = ['', '0', ':', 'a:b', "\0", "a\0b", "\xff\x00\xfe", "\u{00FC}", "\u{00FC}n\u{00EF}:42", 'scope', 'member', 'bridge', "\x00\x00\x00\x01x"];
        $globalPreimages = [];
        $globalKeys = [];

        foreach ($all as $purpose => $totalN) {
            $semanticCount = $totalN - 3;
            $deriverA = new NonAccountScopeKeyDeriver('env-a');
            $baseSemantics = array_fill(0, $semanticCount, 'val');

            // 1. Cross-purpose separation: same (policy, env, semantic fields) across every registered purpose.
            $tuple = [$purpose, 'policy', 'env-a', ...$baseSemantics];
            $preimage = NonAccountScopeKeyDeriver::preimage($tuple);
            self::assertSame($tuple, self::decodeNonAccount($preimage), "Decoder must recover exact tuple for {$purpose}.");
            self::assertArrayNotHasKey($preimage, $globalPreimages, "Purpose {$purpose} must not collide in preimage with another purpose.");
            $globalPreimages[$preimage] = $purpose;

            $digest = $deriverA->derive($purpose, 'policy', self::S, ...$baseSemantics);
            self::assertArrayNotHasKey($digest, $globalKeys, "Purpose {$purpose} must not collide in digest on sample with another purpose.");
            $globalKeys[$digest] = $purpose;

            // 2. Policy/env boundary shift recurrence for every purpose (including exact A4 colon-shift witness).
            $a = (new NonAccountScopeKeyDeriver('c'))->derive($purpose, "a:rate_limiter:{$purpose}:v1:b", self::S, ...$baseSemantics);
            $b = (new NonAccountScopeKeyDeriver("b:rate_limiter:{$purpose}:v1:c"))->derive($purpose, 'a', self::S, ...$baseSemantics);
            self::assertNotSame($a, $b, "Policy/env boundary shift must not collide for {$purpose}.");

            // 3. Opaque delimiter/NUL/binary/empty/0/Unicode corpus across policy, env and semantic slot 0.
            $purposePreimages = [];
            $purposeKeys = [];
            foreach (array_slice($corpus, 0, 7) as $policy) {
                foreach (array_slice($corpus, 0, 7) as $env) {
                    $sem = $baseSemantics;
                    $sem[0] = $policy === '' ? $env : "{$policy}:{$env}\0";
                    $t = [$purpose, $policy, $env, ...$sem];
                    $p = NonAccountScopeKeyDeriver::preimage($t);
                    self::assertSame($t, self::decodeNonAccount($p));
                    self::assertArrayNotHasKey($p, $purposePreimages);
                    $purposePreimages[$p] = true;
                    $k = (new NonAccountScopeKeyDeriver($env))->derive($purpose, $policy, self::S, ...$sem);
                    self::assertArrayNotHasKey($k, $purposeKeys);
                    $purposeKeys[$k] = true;
                }
            }
        }
    }

    public function testCanonicalEquivalentInputsProduceEqualKeysAndDistinctTuplesProduceDistinctKeys(): void
    {
        $deriver = new NonAccountScopeKeyDeriver('prod');

        // IPv4 and IPv4-mapped IPv6 canonicalize to the same prefix.
        self::assertSame(
            $deriver->deriveK1('api_heavy_protection', SourcePrefix::of('192.0.2.77'), self::S),
            $deriver->deriveK1('api_heavy_protection', SourcePrefix::of('::ffff:192.0.2.77'), self::S),
        );

        // Equivalent IPv6 representations within the same /64 canonicalize to the same prefix.
        self::assertSame(
            $deriver->deriveK1('api_heavy_protection', SourcePrefix::of('2001:db8:0:1::1'), self::S),
            $deriver->deriveK1('api_heavy_protection', SourcePrefix::of('2001:0db8:0000:0001:ffff:ffff:ffff:ffff'), self::S),
        );

        // Equivalent UAs canonicalize to the same browser-major token.
        self::assertSame(
            $deriver->deriveK2('api_heavy_protection', '192.0.2.77', DeviceIdentityResolver::normalizeUserAgent('Mozilla/5.0 Chrome/126.0.0.1'), self::S),
            $deriver->deriveK2('api_heavy_protection', '192.0.2.77', DeviceIdentityResolver::normalizeUserAgent('Mozilla/5.0 Chrome/126.0.6478.127'), self::S),
        );

        // Distinct tuples (even differing only by whitespace, empty vs '0', or domain separation against E1) stay distinct.
        self::assertNotSame(
            $deriver->deriveK1('p', '', self::S),
            $deriver->deriveK1('p', '0', self::S),
        );
        self::assertNotSame(
            (new NonAccountScopeKeyDeriver(' prod '))->deriveK1('p', '192.0.2.1', self::S),
            (new NonAccountScopeKeyDeriver('prod'))->deriveK1('p', '192.0.2.1', self::S),
        );
        self::assertNotSame(
            NonAccountScopeKeyDeriver::key(self::S, ['k4', 'login_protection', 'prod-eu', 'acct-0001']),
            substr(E1AccountScopeKeyDeriver::key(self::S, ['k4', 'login_protection', 'prod-eu', 'acct-0001']), 3),
            'Non-account f1 and E1 must remain domain-separated even on identical raw field tuples.',
        );
    }

    /** @return array<int|string, array{int, bool}> */
    public static function u32Bounds(): array
    {
        $rows = [
            '-1 (rejected on both widths)' => [-1, false],
            'PHP_INT_MIN (rejected on both widths)' => [PHP_INT_MIN, false],
            '0' => [0, true],
            '1' => [1, true],
            '2^24' => [1 << 24, true],
            'PHP_INT_MAX' => [PHP_INT_MAX, PHP_INT_SIZE === 4],
        ];
        if (PHP_INT_SIZE === 8) {
            $rows['0xFFFFFFFE'] = [0xFFFFFFFE, true];
            $rows['0xFFFFFFFF'] = [0xFFFFFFFF, true];
            $rows['0x100000000'] = [0x100000000, false];
        }

        return $rows;
    }

    #[DataProvider('u32Bounds')]
    public function testCheckedU32FieldLengthBoundAppliesToNonAccountE1AndSimpleDerivers(int $length, bool $encodes): void
    {
        $seam = static fn(string $field): int => $field === 'target' ? $length : strlen($field);

        $nonAccount = new NonAccountScopeKeyDeriver('env', $seam);
        $e1 = new E1AccountScopeKeyDeriver('env', $seam);
        $simple = new SimpleFixedWindowKeyDeriver('env', $seam);

        if ($encodes) {
            self::assertSame($length, self::u32(KeyPreimageFraming::encodeU32($length, 'test')));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $nonAccount->deriveK1('p', 'target', self::S));
            self::assertStringStartsWith('e1.', $e1->derive('k4', 'p', 'target', self::S));
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $simple->derive('p', 10, 60, 'target', self::S));

            return;
        }

        foreach ([
            'framing' => static fn(): string => KeyPreimageFraming::encodeU32($length, 'test'),
            'non-account' => static fn(): string => $nonAccount->deriveK1('p', 'target', self::S),
            'e1' => static fn(): string => $e1->derive('k4', 'p', 'target', self::S),
            'simple' => static fn(): string => $simple->derive('p', 10, 60, 'target', self::S),
        ] as $label => $call) {
            try {
                $call();
                self::fail("{$label} must reject out-of-range u32be length {$length} before packing.");
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('u32Bounds')]
    public function testCheckedU32CountBoundAppliesToTupleFraming(int $count, bool $encodes): void
    {
        $countSeam = static fn(array $fields): int => $count;
        $nonAccount = new NonAccountScopeKeyDeriver('env', null, $countSeam);
        $e1 = new E1AccountScopeKeyDeriver('env', null, $countSeam);

        if ($encodes) {
            $pre = NonAccountScopeKeyDeriver::preimage(['k1', 'p', 'env', '1.2.3.4'], null, $countSeam);
            $encodedCount = self::u32(substr($pre, strlen(NonAccountScopeKeyDeriver::MAGIC), 4));
            self::assertSame($count, $encodedCount);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $nonAccount->deriveK1('p', '1.2.3.4', self::S));
            self::assertStringStartsWith('e1.', $e1->derive('k4', 'p', 'acct', self::S));

            return;
        }

        foreach ([
            'non-account' => static fn(): string => $nonAccount->deriveK1('p', '1.2.3.4', self::S),
            'e1' => static fn(): string => $e1->derive('k4', 'p', 'acct', self::S),
        ] as $label => $call) {
            try {
                $call();
                self::fail("{$label} must reject out-of-range u32be field count {$count} before packing.");
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSimpleFixedWindowWriterAndReaderRetainExactPreG2Bytes(): void
    {
        $expected = '7986d5743dd9092f6afaf0b23e08e8ec2e971d2e6603ac99619062a55702ef81';
        $deriver = new SimpleFixedWindowKeyDeriver('prod-eu');
        self::assertSame($expected, $deriver->derive('public_burst', 10, 60, 'subj-0001', self::S));

        // Verify live writer and operational reader write/read that exact pre-G2 storage key.
        $clock = new FixedClock('@1793793600');
        $store = new InMemoryRateLimitStore($clock);
        $policy = new FixedWindowThrottlePolicy('public_burst', 10, 60);
        $writer = new FixedWindowSimpleRateLimiter([$policy], $store, $clock, self::S, 'prod-eu');
        $reader = new SimpleRateLimitOperationalReader([$policy], $store, $clock, self::S, 'prod-eu');

        $writer->consume('public_burst', 'subj-0001', 3);
        self::assertSame(3, $store->getBudget($expected)?->count, 'Writer must store under exact pre-G2 simple key bytes.');
        self::assertSame(3, $reader->read('public_burst', 'subj-0001')->count, 'Reader must read the exact same simple key bytes.');
    }

    public function testLocalFallbackIpUaJoinIsDeterministicallyInjectiveEvenForColonBearingInvalidIps(): void
    {
        // Proof witness: normalizeUserAgent() always returns (opera|edge|firefox|chrome|safari|other)/[0-9]+
        // which contains zero colons; therefore the last ':' in "{$normalizedIp}:{$normalizedUa}" uniquely splits IP and UA.
        $rawIps = ['203.0.113.10', '2001:db8::1', 'not:an:ip', 'a:chrome/126', 'a:other/0', ':::', ''];
        $rawUas = [
            'Mozilla/5.0 Chrome/126.0.0.0',
            'Mozilla/5.0 Firefox/128.0',
            'Mozilla/5.0 Edg/126.0',
            'Mozilla/5.0 OPR/111.0',
            'Mozilla/5.0 Version/17.5 Safari/605.1.15',
            'curl/8.7.1',
            'malicious:ua:with:colons',
        ];

        $seen = [];
        foreach ($rawIps as $ip) {
            $normIp = SourcePrefix::of($ip);
            foreach ($rawUas as $ua) {
                $normUa = DeviceIdentityResolver::normalizeUserAgent($ua);
                self::assertStringNotContainsString(':', $normUa);
                $joined = "{$normIp}:{$normUa}";
                $pos = strrpos($joined, ':');
                self::assertIsInt($pos);
                self::assertSame($normIp, substr($joined, 0, $pos));
                self::assertSame($normUa, substr($joined, $pos + 1));
                $pairKey = strlen($normIp) . ':' . $normIp . '|' . $normUa;
                if (isset($seen[$joined])) {
                    self::assertSame($pairKey, $seen[$joined], 'Distinct (normalizedIp, normalizedUa) pairs must never collide.');
                }
                $seen[$joined] = $pairKey;
            }
        }

        // Behavioral verification on LocalFallbackLimiter: colon-bearing invalid IP stays distinct from another pair.
        $clock = new FixedClock('@1793793600');
        $policy = new ApiHeavyProtectionPolicy();
        self::assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', 'x:chrome/126', null, 'curl/8.0'));
        self::assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', 'x', null, 'Mozilla/5.0 Chrome/126.0'));
    }

    public function testDeviceIdentityResolverPipeDelimitedFormatIsDeterministicallyInjectiveAndDomainSeparated(): void
    {
        // Proof witness:
        // 1) "v2|" vs "id1|" are disjoint fixed prefixes.
        // 2) normalizedUa never contains '|'.
        // 3) clientFp is either '' (immediately followed by '|') or canonical JSON object/array (starts with '{' or '[',
        //    never '|', and is strictly prefix-free by balanced JSON braces/brackets outside quoted strings).
        $resolver = new DeviceIdentityResolver(new FingerprintHasher(self::S));

        $cases = [
            ['Mozilla/5.0 Chrome/126.0', null, 'a|b'],
            ['Mozilla/5.0 Chrome/126.0', ['k' => 'x|y'], 'z'],
            ['Mozilla/5.0 Chrome/126.0', ['k' => 'x'], 'y|z'],
            ['Mozilla/5.0 Chrome/126.0', ['k' => 'a}|b'], '{"c":1}|d'],
            ['Mozilla/5.0 Chrome/126.0', ['a' => '1', 'b' => '2'], 'sess|1'],
            ['Mozilla/5.0 Chrome/126.0', null, '{"a":"1","b":"2"}|sess|1'],
        ];

        $hashes = [];
        foreach ($cases as [$ua, $fp, $session]) {
            $dto = $resolver->resolve(new RateLimitContextDTO(
                ip: '203.0.113.1',
                ua: $ua,
                accountId: null,
                clientFingerprint: $fp,
                sessionDeviceId: $session,
                isSessionTrusted: true,
                clientInstanceId: 'inst-1',
            ));
            self::assertNotNull($dto->fingerprintHash);
            self::assertNotNull($dto->deviceIdHash);
            self::assertNotSame($dto->fingerprintHash, $dto->deviceIdHash, 'v2| fingerprint and id1| instance id stay distinct.');
            self::assertArrayNotHasKey($dto->fingerprintHash, $hashes, 'Distinct (ua, clientFp, session) tuples must never collide.');
            $hashes[$dto->fingerprintHash] = true;
        }
    }

    public function testSourceGuardNoLegacyColonJoinedNonAccountKeyHmacRemainsInSrc(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offending = [];
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getFilename();
            $code = (string) file_get_contents($file->getPathname());

            if ($name === 'RateLimitOperationalReader.php' && str_contains($code, 'hash_hmac(')) {
                $offending[] = "{$name}: direct hash_hmac must not exist in operational reader";
            }
            if (($name === 'FixedWindowSimpleRateLimiter.php' || $name === 'SimpleRateLimitOperationalReader.php')
                && (str_contains($code, 'hash_hmac(') || str_contains($code, "pack('N'"))) {
                $offending[] = "{$name}: simple key derivation must go through SimpleFixedWindowKeyDeriver";
            }
            if (preg_match('/rate_limiter:(k[123]|correlation|ipv6_adaptive):/', $code) === 1) {
                $offending[] = "{$name}: legacy colon-joined non-account preimage literal remains";
            }
        }

        self::assertSame([], $offending);
    }

    private static function u32(string $bytes): int
    {
        $unpacked = unpack('N', $bytes);
        self::assertIsArray($unpacked);
        $value = $unpacked[1];
        self::assertIsInt($value);

        return $value;
    }

    /** @return list<string>|null */
    private static function decodeNonAccount(string $preimage): ?array
    {
        if (! str_starts_with($preimage, NonAccountScopeKeyDeriver::MAGIC)) {
            return null;
        }
        $offset = strlen(NonAccountScopeKeyDeriver::MAGIC);
        $count = self::u32(substr($preimage, $offset, 4));
        $offset += 4;
        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            $length = self::u32(substr($preimage, $offset, 4));
            $offset += 4;
            $fields[] = substr($preimage, $offset, $length);
            $offset += $length;
        }

        return $offset === strlen($preimage) ? $fields : null;
    }
}
