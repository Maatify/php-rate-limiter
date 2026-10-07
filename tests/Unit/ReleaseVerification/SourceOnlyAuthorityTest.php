<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceFixtures;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\QualificationEvidenceSchema;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseContract;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseRepositoryFixture as Fx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F94-07: approved Composer channels are HTTPS-only (qualifying PAV uses secure-http: true).
 * F94-08: Owner approval is one exact, machine-verifiable representation shared by RAV and the
 * qualification-evidence schema; negated or free-text authority statements never qualify.
 */
final class SourceOnlyAuthorityTest extends TestCase
{
    private const string TARGET = '1.0.0-rc.3';
    private const string ID = 'DEC-100';

    // ------------------------------------------------------------ F94-07: channel validator

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function channels(): iterable
    {
        yield 'https' => ['https://packages.example.test', true];
        yield 'https with path' => ['https://packages.example.test/maatify', true];
        yield 'https with port' => ['https://packages.example.test:8443/maatify', true];
        yield 'default packagist channel' => [ReleaseContract::DEFAULT_DISTRIBUTION_CHANNEL, true];
        yield 'http' => ['http://packages.example.test', false];
        yield 'HTTP upper-case' => ['HTTP://packages.example.test', false];
        yield 'ftp' => ['ftp://packages.example.test', false];
        yield 'userinfo' => ['https://user:pw@packages.example.test', false];
        yield 'user only' => ['https://user@packages.example.test', false];
        yield 'empty userinfo' => ['https://@packages.example.test', false];
        yield 'query' => ['https://packages.example.test/p?token=abc', false];
        yield 'empty query' => ['https://packages.example.test/p?', false];
        yield 'fragment' => ['https://packages.example.test/p#frag', false];
        yield 'empty fragment' => ['https://packages.example.test/p#', false];
        yield 'missing scheme' => ['packages.example.test', false];
        yield 'scheme relative' => ['//packages.example.test', false];
        yield 'missing host' => ['https://', false];
        yield 'empty host with path' => ['https:///maatify', false];
        yield 'malformed' => ['https:::packages', false];
        yield 'leading space' => [' https://packages.example.test', false];
        yield 'trailing newline' => ["https://packages.example.test\n", false];
        yield 'embedded space' => ['https://packages .example.test', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('channels')]
    public function testCanonicalChannelValidatorIsHttpsOnly(string $channel, bool $valid): void
    {
        self::assertSame($valid, ReleaseContract::isValidChannel($channel), $channel);
    }

    public function testNormalizationNeverTurnsAnInsecureChannelIntoAValidOne(): void
    {
        $normalized = ReleaseContract::normalizeChannel('HTTP://Packages.Example.Test/');

        self::assertSame('http://packages.example.test', $normalized);
        self::assertFalse(ReleaseContract::isValidChannel($normalized));
    }

    public function testPavConsumerManifestStillEnforcesSecureHttp(): void
    {
        $manifest = (new PublishedArtifactVerifier())->buildConsumerManifest('maatify/php-rate-limiter', self::TARGET, Fx::CHANNEL);

        self::assertTrue($manifest['config']['secure-http']);
    }

    // ------------------------------------------------------------ F94-07: boundaries

    #[DataProvider('rejectedChannels')]
    public function testRavSourceOnlyQualificationRejectsNonHttpsOrDecoratedChannels(string $channel): void
    {
        $result = $this->qualify(['Composer Channel' => $channel]);

        self::assertSame('FAIL', $result['status'], $channel);
        self::assertStringContainsString('channel', $result['message']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedChannels(): iterable
    {
        yield 'http' => ['http://packages.example.test'];
        yield 'credentials' => ['https://user:pw@packages.example.test'];
        yield 'query' => ['https://packages.example.test/p?token=abc'];
        yield 'fragment' => ['https://packages.example.test/p#frag'];
    }

    public function testRavSourceOnlyQualificationAcceptsHttpsChannel(): void
    {
        $result = $this->qualify(['Composer Channel' => 'https://packages.example.test/maatify']);

        self::assertSame('PASS', $result['status'], $result['message']);
        self::assertSame('https://packages.example.test/maatify', $result['details']['approved_distribution_channel'] ?? null);
    }

    public function testSchemaRejectsFabricatedHttpChannelEvidence(): void
    {
        $evidence = EvidenceFixtures::validSourceOnly();
        self::assertSame([], QualificationEvidenceSchema::validate($evidence));

        $evidence['approved_distribution_channel'] = 'http://packages.example.test';
        $decision = $evidence['source_only_decision'];
        self::assertIsArray($decision);
        $decision['approved_distribution_channel'] = 'http://packages.example.test';
        $evidence['source_only_decision'] = $decision;

        self::assertStringContainsString('approved_distribution_channel', implode('|', QualificationEvidenceSchema::validate($evidence)));
    }

    #[DataProvider('rejectedChannels')]
    public function testSchemaRejectsDecoratedChannels(string $channel): void
    {
        $evidence = EvidenceFixtures::validSourceOnly();
        $evidence['approved_distribution_channel'] = $channel;

        self::assertNotSame([], QualificationEvidenceSchema::validate($evidence), $channel);
    }

    public function testPavRejectsHttpChannelEvidenceBeforeAnyComposerWork(): void
    {
        $evidence = EvidenceFixtures::validSourceOnly();
        $evidence['approved_distribution_channel'] = 'http://packages.example.test';
        $decision = $evidence['source_only_decision'];
        self::assertIsArray($decision);
        $decision['approved_distribution_channel'] = 'http://packages.example.test';
        $evidence['source_only_decision'] = $decision;

        $this->assertRejectedBeforeComposer($evidence, 'approved_distribution_channel');
    }

    // ------------------------------------------------------------ F94-08: Owner approval

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonCanonicalAuthorityStatements(): iterable
    {
        yield 'negated' => ['Not Owner-approved package delivery decision.'];
        yield 'question answered no' => ['Owner-approved? No.'];
        yield 'later retraction' => ['Owner-approved package delivery decision. Not approved by Owner.'];
        yield 'extra trailing text' => ['Owner-approved package delivery decision. See thread.'];
        yield 'wrong case' => ['owner-approved package delivery decision.'];
        yield 'missing full stop' => ['Owner-approved package delivery decision'];
        yield 'looser wording' => ['Owner-approved RC2 remediation direction.'];
        yield 'maintainer proposed' => ['Proposed by a maintainer.'];
        yield 'empty' => [''];
    }

    #[DataProvider('nonCanonicalAuthorityStatements')]
    public function testRavRejectsNonCanonicalDecisionAuthorityStatements(string $statement): void
    {
        $result = $this->qualify([], authority: $statement);

        self::assertSame('FAIL', $result['status'], $statement);
        self::assertStringContainsString('Owner approval', $result['message']);
    }

    public function testRavAcceptsOnlyTheCanonicalPositiveRepresentation(): void
    {
        $result = $this->qualify([], authority: 'Owner-approved package delivery decision.');

        self::assertSame('PASS', $result['status'], $result['message']);
        $owner = $result['details']['owner_approval'] ?? null;
        self::assertIsArray($owner);
        self::assertSame('Owner-approved package delivery decision.', $owner['authority_statement']);
        self::assertSame('Package Owner', $owner['approving_authority']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function nonCanonicalDeclarations(): iterable
    {
        yield 'state PENDING' => [['Owner Approval' => 'PENDING']];
        yield 'state lower-case' => [['Owner Approval' => 'approved']];
        yield 'state negated' => [['Owner Approval' => 'NOT APPROVED']];
        yield 'state missing' => [['Owner Approval' => '']];
        yield 'authority missing' => [['Approving Authority' => '']];
        yield 'authority Maintainer' => [['Approving Authority' => 'Maintainer']];
        yield 'authority wrong case' => [['Approving Authority' => 'package owner']];
        yield 'authority decorated' => [['Approving Authority' => 'Package Owner (acting)']];
        yield 'authority negated' => [['Approving Authority' => 'Not Package Owner']];
    }

    /**
     * @param array<string, string> $declaration
     */
    #[DataProvider('nonCanonicalDeclarations')]
    public function testRavRejectsNonCanonicalOwnerApprovalDeclarations(array $declaration): void
    {
        $result = $this->qualify($declaration);

        self::assertSame('FAIL', $result['status'], (string) json_encode($declaration));
        self::assertStringContainsString('canonical Owner approval evidence', $result['message']);
    }

    /**
     * @param array<string, string> $mutation
     */
    #[DataProvider('fabricatedOwnerEvidence')]
    public function testSchemaAndPavRejectFabricatedNonCanonicalOwnerEvidence(array $mutation): void
    {
        $evidence = EvidenceFixtures::validSourceOnly();
        $decision = $evidence['source_only_decision'];
        self::assertIsArray($decision);
        $owner = $decision['owner_approval'];
        self::assertIsArray($owner);
        $decision['owner_approval'] = array_merge($owner, $mutation);
        $evidence['source_only_decision'] = $decision;

        self::assertStringContainsString('Owner approval', implode('|', QualificationEvidenceSchema::validate($evidence)));
        $this->assertRejectedBeforeComposer($evidence, 'Owner approval');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function fabricatedOwnerEvidence(): iterable
    {
        yield 'negated statement' => [['authority_statement' => 'Not Owner-approved package delivery decision.']];
        yield 'question answered no' => [['authority_statement' => 'Owner-approved? No.']];
        yield 'retracted' => [['authority_statement' => 'Owner-approved package delivery decision. Not approved by Owner.']];
        yield 'free text' => [['authority_statement' => 'Owner-approved RC2 remediation direction.']];
        yield 'authority Maintainer' => [['approving_authority' => 'Maintainer']];
        yield 'authority empty' => [['approving_authority' => '']];
        yield 'authority wrong case' => [['approving_authority' => 'package owner']];
    }

    public function testCanonicalOwnerEvidenceSatisfiesTheSchema(): void
    {
        self::assertSame([], QualificationEvidenceSchema::validate(EvidenceFixtures::validSourceOnly()));
    }

    public function testRavAndSchemaShareOneCanonicalDefinition(): void
    {
        self::assertTrue(ReleaseContract::isCanonicalOwnerAuthorityStatement('Owner-approved package delivery decision.'));
        self::assertTrue(ReleaseContract::isCanonicalOwnerApprovalState('APPROVED'));
        self::assertTrue(ReleaseContract::isCanonicalApprovingAuthority('Package Owner'));
        self::assertFalse(ReleaseContract::isCanonicalOwnerAuthorityStatement('Owner-approved package delivery decision.' . "\n"));
        self::assertFalse(ReleaseContract::isCanonicalApprovingAuthority('Maintainer'));
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, string> $declaration
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>|null}
     */
    private function qualify(array $declaration, string $authority = 'Owner-approved package delivery decision.'): array
    {
        $repo = new Fx();
        try {
            $repo->populateRelease(self::TARGET);
            $repo->write(Fx::decisionPath(self::ID), Fx::decisionRecord(self::ID, declaration: $declaration, authority: $authority));
            $repo->write('docs/decisions/DECISIONS_INDEX.md', Fx::decisionIndex([['id' => self::ID, 'status' => 'ACTIVE']]));
            $commit = $repo->commit('decision');

            return (new ReleaseArtifactVerifier())->evaluateSourceOnlyPolicy(
                ['decision_id' => self::ID, 'decision_file' => Fx::decisionPath(self::ID), 'commit_ref' => $commit],
                $repo->path,
                self::TARGET,
                $commit,
                'source-only',
                gmdate('Y-m-d\TH:i:s\Z'),
            );
        } finally {
            $repo->cleanup();
        }
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function assertRejectedBeforeComposer(array $evidence, string $messageFragment): void
    {
        $result = (new PublishedArtifactVerifier('/nonexistent/composer'))->verify(['qualification_evidence' => $evidence]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['qualification_evidence']['status']);
        self::assertStringContainsString($messageFragment, $result['checks']['qualification_evidence']['message']);
        foreach (['approved_channel', 'source_only_decision', 'composer_version_evidence', 'preferred_install_evidence', 'composer_resolution'] as $reached) {
            self::assertArrayNotHasKey($reached, $result['checks'], $reached . ' must not be reached');
        }
        self::assertSame('UNRESOLVED', $result['installation_mode']);
        self::assertSame('', $result['installed_path']);
    }
}
