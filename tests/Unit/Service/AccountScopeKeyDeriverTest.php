<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\V2AccountScopeKeyDeriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-044 / E1 contract AC-1, AC-2 and AC-3 (source part): the vectors, the V2 goldens, the injectivity of the encoding, the length bound
 * and the single derivation source. Synthetic data only; the secrets are fixtures.
 */
#[Group('php32')]
#[Group('key-scope-e1')]
final class AccountScopeKeyDeriverTest extends TestCase
{
    private const S = 'synthetic-key-secret-not-for-production';
    private const SP = 'synthetic-previous-secret-not-for-production';

    protected function setUp(): void
    {
        parent::setUp();
        // The raw width is part of the evidence: it is asserted, never assumed.
        self::assertContains(PHP_INT_SIZE, [4, 8]);
    }

    /** @return array<string, array{string, string, list<string>, string}> */
    public static function vectors(): array
    {
        return [
            'K4' => [self::S, 'k4', ['login_protection', 'prod-eu', 'acct-0001'], 'e1.9678f6f2458e4fc59a0b91701343b4a1e07c81144d9e40025bb2e9aa2b825fa7'],
            'K4P' => [self::S, 'k4p', ['login_protection', 'prod-eu', 'acct-0001'], 'e1.38940a4b90349291e802f881e122cf53496398db08844cb5760310593e1c9b5a'],
            'K4 Previous' => [self::SP, 'k4', ['login_protection', 'prod-eu', 'acct-0001'], 'e1.edfab467ae05965b5e16781013c16e3a20fc6b51bd3e065ba08a3aaf88d0e7ae'],
            'K5' => [self::S, 'k5', ['login_protection', 'prod-eu', 'acct-0001', 'f00dcafe'], 'e1.493ca457ebe7b232f740cd9d57ad046e0185e2e3ebe1ea5bb47ddd58d23193a4'],
            'bytes not characters' => [self::S, 'k4', ['login_protection', 'prod:eu', "\u{00FC}n\u{00EF}:42"], 'e1.9692c65562144f66c8d9af55a9adcd519f6d623ec3a3cea8782a3f6e52896de8'],
            'cooldown' => [self::S, 'budget_cooldown', ['otp_verification', 'prod-eu', 'acct-0001'], 'e1.3ff1b220ae56440f6a2b8215e5b0af16edc94444d315450f3620747e67ea80ca'],
            'micro-cap' => [self::S, 'microcap.k5', ['login_protection', 'prod-eu', 'acct-0001', 'f00dcafe'], 'e1.111e12c1ee52d71bb982ff4dc88ac854b9d6c47f4b9664e823d47ea167ef7eb8'],
        ];
    }

    /** @param list<string> $rest */
    #[DataProvider('vectors')]
    public function testTheE1VectorsReproduceByteForByteOnBothWidths(string $secret, string $purpose, array $rest, string $expected): void
    {
        $fields = [$purpose, ...$rest];
        $viaStatic = E1AccountScopeKeyDeriver::key($secret, $fields);
        self::assertSame($expected, $viaStatic);

        // The instance path: policy, env, account [, material] are the fields after the purpose.
        $deriver = new E1AccountScopeKeyDeriver($rest[1]);
        $material = count($rest) === 4 ? $rest[3] : null;
        self::assertSame($expected, $deriver->derive($purpose, $rest[0], $rest[2], $secret, $material));
    }

    public function testThePreimageFramingMatchesTheHandCheckedHex(): void
    {
        $preimage = E1AccountScopeKeyDeriver::preimage(['k4', 'login_protection', 'prod-eu', 'acct-0001']);

        self::assertSame(
            '6d6161746966792e726174652d6c696d697465722e73636f70652e653100' . '00000004'
            . '00000002' . '6b34'
            . '00000010' . '6c6f67696e5f70726f74656374696f6e'
            . '00000007' . '70726f642d6575'
            . '00000009' . '616363742d30303031',
            bin2hex($preimage),
        );
    }

    /** @return array<string, array{string, string, ?string, string}> */
    public static function v2Goldens(): array
    {
        // Taken from the derivation of main@4c523096 before it moved behind the component: the same preimage text and plain HMAC.
        return [
            'k4' => ['k4', 'acct-0001', null, '20566b825dbffc26f5c254f38d525855a1c2626453ae14e57373d1708df60649'],
            'k4p' => ['k4p', 'acct-0001', null, '73e8b0b52ead67457c981db4f5af1814e235183068b575b9a8db6a692db8d49d'],
            'k5' => ['k5', 'acct-0001', 'f00dcafe', '00a67f5c4d4304019508baf1290be16a19d8172e88fba49ee85a965d2d9c0843'],
            'k5p' => ['k5p', 'acct-0001', 'f00dcafe', 'fc678e7d624a63fe339babf0437fca18d465fbe4a1ec25479fffe96921c1c743'],
            'microcap.k5' => ['microcap.k5', 'acct-0001', 'f00dcafe', '2198108d49a1eaa624544cb90f76e9e5e3155926ec537aa85c6e8af572d4f932'],
            'microcap.k5p' => ['microcap.k5p', 'acct-0001', 'f00dcafe', 'da969ce4fecadc4be0f97e8cad08e225266254861e81a2078ebc78d1d84ad45b'],
            'budget_cooldown' => ['budget_cooldown', 'acct-0001', null, '96c613a3a610596703147642dce82d3b2dcd814db6a85326ce5a68e90781e461'],
            'budget_cooldown_p' => ['budget_cooldown_p', 'acct-0001', null, '5b7cb09e22a6a874a9645de3acff773e189a2cbf5ecb5b8c1ab2249167e1a173'],
            'aux.flood_stage' => ['aux.flood_stage', 'acct-0001', null, '428124de9cccacb89df6c3db1ae3a7f4ccf27c225f66b3ae67a53edc216c59fe'],
            'aux.anti_equilibrium_device_p' => ['aux.anti_equilibrium_device_p', 'acct-0001', 'f00dcafe', 'ea548e4c5b947c6abff1ea6d71f149567b26c7e1b26b4d71d537e993ef516c48'],
        ];
    }

    #[DataProvider('v2Goldens')]
    public function testTheV2DerivationIsByteIdenticalToTheShippedOne(string $purpose, string $account, ?string $material, string $expected): void
    {
        $deriver = new V2AccountScopeKeyDeriver('prod-eu');

        self::assertSame($expected, $deriver->derive($purpose, 'login_protection', $account, self::S, $material));
    }

    public function testAPurposeAndItsMaterialMustMatchTheRegistry(): void
    {
        $e1 = new E1AccountScopeKeyDeriver('prod-eu');
        foreach ([['k4', 'material'], ['k5', null], ['unknown.purpose', null]] as [$purpose, $material]) {
            try {
                $e1->derive($purpose, 'p', 'a', self::S, $material);
                self::fail('A purpose that does not match the registry must be refused.');
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<int|string, array{int, bool}> */
    public static function lengths(): array
    {
        $rows = ['0' => [0, true], '1' => [1, true], '2^24' => [1 << 24, true], 'PHP_INT_MAX' => [PHP_INT_MAX, PHP_INT_SIZE === 4]];
        if (PHP_INT_SIZE === 8) {
            $rows['0xFFFFFFFE'] = [0xFFFFFFFE, true];
            $rows['0xFFFFFFFF'] = [0xFFFFFFFF, true];
            $rows['0x100000000'] = [0x100000000, false];
        }

        return $rows;
    }

    #[DataProvider('lengths')]
    public function testTheLengthBoundIsEnforcedBeforePackingWithoutAllocatingTheField(int $length, bool $encodes): void
    {
        $seam = static fn(string $field): int => $field === 'long' ? $length : strlen($field);
        $e1 = new E1AccountScopeKeyDeriver('env', $seam);

        if ($encodes) {
            $preimage = E1AccountScopeKeyDeriver::preimage(['k4', 'p', 'env', 'long'], $seam);
            $prefix = substr($preimage, -(4 + 4), 4);
            self::assertSame($length, self::u32($prefix), 'The prefix equals the supplied length.');
            self::assertStringStartsWith('e1.', $e1->derive('k4', 'p', 'long', self::S));

            return;
        }
        $this->expectException(RateLimiterException::class);
        $e1->derive('k4', 'p', 'long', self::S);
    }

    public function testOnA32BitBuildTheRefusalIsUnreachableAndEveryRepresentableLengthEncodes(): void
    {
        if (PHP_INT_SIZE !== 4) {
            self::markTestSkipped('Only a 32-bit build records the unreachable refusal.');
        }
        self::assertSame(0x7FFFFFFF, PHP_INT_MAX, 'strlen cannot exceed PHP_INT_MAX here, so no field can reach 2^32.');
    }

    /** @return list<string> */
    private static function corpus(): array
    {
        return ['', 'a', ':', 'a:b', "\0", "a\0b", "\u{00FC}", "\u{00FC}n\u{00EF}", 'k4', str_repeat('x', 300), "\x00\x00\x00\x01a"];
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
    private static function decode(string $preimage): ?array
    {
        if (! str_starts_with($preimage, E1AccountScopeKeyDeriver::MAGIC)) {
            return null;
        }
        $offset = strlen(E1AccountScopeKeyDeriver::MAGIC);
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

    public function testTheEncodingIsInjectiveOnTheCorpusAndEveryPreimageDecodesToItsTuple(): void
    {
        $corpus = self::corpus();
        $seen = [];
        $keys = [];
        foreach ($corpus as $policy) {
            foreach ($corpus as $env) {
                foreach ($corpus as $account) {
                    $tuple = ['k4', $policy, $env, $account];
                    $preimage = E1AccountScopeKeyDeriver::preimage($tuple);
                    self::assertSame($tuple, self::decode($preimage), 'A decoder recovers the exact tuple.');
                    self::assertArrayNotHasKey($preimage, $seen, 'Distinct tuples never share a preimage.');
                    $seen[$preimage] = true;
                    $key = E1AccountScopeKeyDeriver::key(self::S, $tuple);
                    self::assertArrayNotHasKey($key, $keys, 'Distinct tuples never share a key on the sample.');
                    $keys[$key] = true;
                }
            }
        }
        // The V2 ambiguity E1 removes: two different (env, account) pairs that join to the same V2 text.
        $v2a = new V2AccountScopeKeyDeriver('prod:eu');
        $v2b = new V2AccountScopeKeyDeriver('prod');
        self::assertSame(
            $v2a->derive('k4', 'p', 'x', self::S),
            $v2b->derive('k4', 'p', 'eu:x', self::S),
            'V2 maps these two scopes to one key (the study #290 gap).',
        );
        self::assertNotSame(
            (new E1AccountScopeKeyDeriver('prod:eu'))->derive('k4', 'p', 'x', self::S),
            (new E1AccountScopeKeyDeriver('prod'))->derive('k4', 'p', 'eu:x', self::S),
        );
    }

    public function testNoHmacOverAnAccountBearingPreimageRemainsOutsideTheDerivationComponent(): void
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offending = [];
        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $name = $file->getFilename();
            if ($name === 'E1AccountScopeKeyDeriver.php' || $name === 'V2AccountScopeKeyDeriver.php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // Admission and simple-throttle derivations have their own surface (E1 contract §5.3, §5.4) and keep their own text.
            if (str_contains($name, 'Admission') || str_contains($name, 'FixedWindowSimple') || str_contains($name, 'SimpleRateLimitOperationalReader')) {
                continue;
            }
            if (preg_match('/(hashKey|hash_hmac)\(\s*["\'][^"\']*accountId/', $code) === 1) {
                $offending[] = $name;
            }
        }

        self::assertSame([], $offending, 'Every account-bearing derivation goes through the single component.');
    }
}
