<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

/**
 * Test-only builders of schema-valid RAV qualification evidence.
 */
final class EvidenceFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function validDist(string $target = '1.0.0-rc.3', ?string $sha = null): array
    {
        $sha ??= str_repeat('a', 40);
        $manifest = [];
        foreach (ReleaseContract::REQUIRED_PATHS as $path) {
            $manifest[$path] = hash('sha256', $path);
        }
        ksort($manifest);

        return [
            'schema_version' => ReleaseContract::EVIDENCE_SCHEMA_VERSION,
            'status' => 'PASS',
            'package_name' => ReleaseContract::PACKAGE_NAME,
            'target_version' => $target,
            'candidate_sha' => $sha,
            'candidate_tree_sha' => str_repeat('b', 40),
            'qualification_started_at' => '2026-10-06T20:00:00Z',
            'qualified_at' => '2026-10-06T20:05:00Z',
            'delivery_policy' => 'dist',
            'approved_distribution_channel' => ReleaseContract::DEFAULT_DISTRIBUTION_CHANNEL,
            'semantic_review' => [
                'schema_version' => '1.0.0',
                'target' => $target,
                'candidate_sha' => $sha,
                'reviewer' => 'Lead Reviewer',
                'reviewed_at' => '2026-10-06T19:00:00Z',
                'disposition' => 'APPROVED',
                'claims' => array_fill_keys(ReleaseContract::REQUIRED_SEMANTIC_CLAIMS, 'CONFIRMED'),
                'notes' => null,
            ],
            'content_manifest' => $manifest,
            'distribution_evidence' => [
                'archive_format' => 'git-archive',
                'archive_verified' => true,
                'archive_entry_count' => 12,
                'required_files_in_archive' => 11,
                'required_files_missing' => [],
                'archive_content_matches_tree' => true,
                'forbidden_entries' => [],
                'gitattributes_export_ignore' => ['status' => 'NOT_APPLICABLE'],
                'composer_archive_exclude' => ['status' => 'NOT_APPLICABLE', 'patterns' => []],
            ],
            'source_only_decision' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validSourceOnly(string $target = '1.0.0-rc.3', ?string $sha = null): array
    {
        $evidence = self::validDist($target, $sha);
        $evidence['delivery_policy'] = 'source-only';
        $evidence['approved_distribution_channel'] = ReleaseRepositoryFixture::CHANNEL;
        $evidence['source_only_decision'] = [
            'decision_id' => 'DEC-100',
            'decision_file' => 'docs/decisions/DEC-100_TEST.md',
            'decision_commit' => str_repeat('c', 40),
            'record_blob_sha' => str_repeat('d', 40),
            'index_file' => SourceOnlyDecisionVerifier::INDEX_PATH,
            'index_blob_sha_at_qualification' => str_repeat('e', 40),
            'status_at_qualification' => 'ACTIVE',
            'index_status_at_qualification' => 'ACTIVE',
            'owner_approval' => [
                'authority_statement' => 'Owner-approved package delivery decision.',
                'approving_authority' => 'Package Owner',
                'approval_date' => '2026-10-01',
                'effective_date' => '2026-10-01',
            ],
            'effective_at' => '2026-10-01T23:59:59Z',
            'package_name' => ReleaseContract::PACKAGE_NAME,
            'approved_distribution_channel' => ReleaseRepositoryFixture::CHANNEL,
            'version_scope' => '1.0.x',
            'scope_covers_target' => true,
            'delivery_mode' => 'source-only',
            'intentional_canonical_delivery' => true,
            'rationale' => 'No dist offered.',
            'maintenance_owner' => 'Maatify Maintainers',
            'qualification_target' => $target,
            'candidate_sha' => $evidence['candidate_sha'],
            'qualification_started_at' => $evidence['qualification_started_at'],
        ];

        return $evidence;
    }
}
