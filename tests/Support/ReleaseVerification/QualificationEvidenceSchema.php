<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

/**
 * The single canonical RAV qualification-evidence schema.
 *
 * RAV validates the evidence it emits against this schema, and PAV rejects anything
 * that does not satisfy it (before any network work). Shape (schema_version 2.0.0):
 *
 *   schema_version, status=PASS, package_name, target_version, candidate_sha,
 *   candidate_tree_sha, qualification_started_at, qualified_at, delivery_policy,
 *   approved_distribution_channel, semantic_review{...}, content_manifest{path: sha256},
 *   distribution_evidence{...}, source_only_decision (null for dist; complete object for source-only)
 */
final class QualificationEvidenceSchema
{
    /** @var list<string> */
    private const array TOP_LEVEL_KEYS = [
        'schema_version',
        'status',
        'package_name',
        'target_version',
        'candidate_sha',
        'candidate_tree_sha',
        'qualification_started_at',
        'qualified_at',
        'delivery_policy',
        'approved_distribution_channel',
        'semantic_review',
        'content_manifest',
        'distribution_evidence',
        'source_only_decision',
    ];

    /** @var list<string> */
    private const array SOURCE_ONLY_KEYS = [
        'decision_id',
        'decision_file',
        'decision_commit',
        'record_blob_sha',
        'index_file',
        'index_blob_sha_at_qualification',
        'status_at_qualification',
        'index_status_at_qualification',
        'owner_approval',
        'effective_at',
        'package_name',
        'approved_distribution_channel',
        'version_scope',
        'scope_covers_target',
        'delivery_mode',
        'intentional_canonical_delivery',
        'rationale',
        'maintenance_owner',
        'qualification_target',
        'candidate_sha',
        'qualification_started_at',
    ];

    /**
     * @param array<string, mixed> $evidence
     * @return list<string> human-readable violations; empty means the evidence is complete and coherent
     */
    public static function validate(array $evidence): array
    {
        $errors = [];

        foreach (self::TOP_LEVEL_KEYS as $key) {
            if (! array_key_exists($key, $evidence)) {
                $errors[] = sprintf('missing field "%s"', $key);
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $str = static fn(string $key): string => is_string($evidence[$key]) ? $evidence[$key] : '';

        if ($str('schema_version') !== ReleaseContract::EVIDENCE_SCHEMA_VERSION) {
            $errors[] = sprintf('unknown schema_version "%s" (expected "%s")', $str('schema_version'), ReleaseContract::EVIDENCE_SCHEMA_VERSION);
        }
        if ($str('status') !== 'PASS') {
            $errors[] = sprintf('status is "%s", expected "PASS"', $str('status'));
        }
        if ($str('package_name') !== ReleaseContract::PACKAGE_NAME) {
            $errors[] = sprintf('package_name "%s" is not "%s"', $str('package_name'), ReleaseContract::PACKAGE_NAME);
        }

        $target = $str('target_version');
        if (! (bool) preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $target)) {
            $errors[] = sprintf('invalid target_version "%s"', $target);
        }
        $candidate = $str('candidate_sha');
        if (! ReleaseContract::isSha($candidate)) {
            $errors[] = 'invalid candidate_sha';
        }
        if (! ReleaseContract::isSha($str('candidate_tree_sha'))) {
            $errors[] = 'invalid candidate_tree_sha';
        }

        $startedAt = ReleaseContract::parseTimestamp($str('qualification_started_at'));
        $qualifiedAt = ReleaseContract::parseTimestamp($str('qualified_at'));
        if ($startedAt === null || $qualifiedAt === null) {
            $errors[] = 'qualification_started_at/qualified_at must be YYYY-MM-DDTHH:MM:SSZ';
        } elseif ($qualifiedAt < $startedAt) {
            $errors[] = 'qualified_at precedes qualification_started_at';
        }

        $policy = $str('delivery_policy');
        if (! in_array($policy, ReleaseContract::DELIVERY_POLICIES, true)) {
            $errors[] = sprintf('unknown delivery_policy "%s"', $policy);
        }

        $channel = $str('approved_distribution_channel');
        if (! ReleaseContract::isValidChannel($channel)) {
            $errors[] = 'approved_distribution_channel is missing or not a valid credential-free repository URL';
        } elseif ($policy === 'dist' && ReleaseContract::normalizeChannel($channel) !== ReleaseContract::normalizeChannel(ReleaseContract::DEFAULT_DISTRIBUTION_CHANNEL)) {
            $errors[] = 'approved_distribution_channel is not the package-approved channel for the dist delivery policy';
        }

        array_push($errors, ...self::validateSemanticReview($evidence['semantic_review'], $target, $candidate));
        array_push($errors, ...self::validateContentManifest($evidence['content_manifest']));
        array_push($errors, ...self::validateDistributionEvidence($evidence['distribution_evidence']));

        $decision = $evidence['source_only_decision'];
        if ($policy === 'dist') {
            if ($decision !== null) {
                $errors[] = 'source_only_decision must be null for the dist delivery policy';
            }
        } elseif ($policy === 'source-only') {
            if (! is_array($decision)) {
                $errors[] = 'source_only_decision is mandatory (complete immutable qualification-time evidence) for source-only';
            } else {
                /** @var array<string, mixed> $decision */
                array_push($errors, ...self::validateSourceOnly($decision, $target, $candidate, $channel, $str('qualification_started_at')));
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateSemanticReview(mixed $review, string $target, string $candidate): array
    {
        if (! is_array($review) || $review === []) {
            return ['semantic_review is absent or empty'];
        }
        $errors = [];
        $get = static fn(string $k): string => is_string($review[$k] ?? null) ? $review[$k] : '';

        if ($get('schema_version') !== ReleaseContract::SEMANTIC_REVIEW_SCHEMA_VERSION) {
            $errors[] = 'semantic_review has the wrong schema_version';
        }
        if ($get('target') !== $target) {
            $errors[] = 'semantic_review target does not match target_version';
        }
        if (strtolower($get('candidate_sha')) !== strtolower($candidate)) {
            $errors[] = 'semantic_review candidate_sha does not match candidate_sha';
        }
        if ($get('disposition') !== 'APPROVED') {
            $errors[] = 'semantic_review disposition is not APPROVED';
        }
        if ($get('reviewer') === '' || ReleaseContract::parseTimestamp($get('reviewed_at')) === null) {
            $errors[] = 'semantic_review lacks reviewer identity or a valid reviewed_at timestamp';
        }
        $claims = $review['claims'] ?? null;
        if (! is_array($claims)) {
            $errors[] = 'semantic_review claims are missing';
        } else {
            foreach (ReleaseContract::REQUIRED_SEMANTIC_CLAIMS as $claim) {
                if (($claims[$claim] ?? null) !== 'CONFIRMED') {
                    $errors[] = sprintf('semantic_review claim "%s" is not CONFIRMED', $claim);
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateContentManifest(mixed $manifest): array
    {
        if (! is_array($manifest) || $manifest === []) {
            return ['content_manifest is absent or empty'];
        }
        $errors = [];
        foreach ($manifest as $path => $hash) {
            $path = (string) $path;
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, '//') || str_ends_with($path, '/') || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
                $errors[] = sprintf('content_manifest has a malformed/ambiguous path "%s"', $path);
            }
            if (! is_string($hash) || ! ReleaseContract::isSha256($hash)) {
                $errors[] = sprintf('content_manifest hash for "%s" is not a SHA-256 value', $path);
            }
        }
        $missing = array_diff(ReleaseContract::REQUIRED_PATHS, array_map('strval', array_keys($manifest)));
        if ($missing !== []) {
            $errors[] = 'content_manifest is incomplete; missing: ' . implode(', ', $missing);
        }
        $extra = array_diff(array_map('strval', array_keys($manifest)), ReleaseContract::REQUIRED_PATHS);
        if ($extra !== []) {
            $errors[] = 'content_manifest has unexpected entries: ' . implode(', ', $extra);
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateDistributionEvidence(mixed $dist): array
    {
        if (! is_array($dist) || $dist === []) {
            return ['distribution_evidence is absent or empty'];
        }
        $errors = [];
        if (($dist['archive_format'] ?? null) !== 'git-archive' || ($dist['archive_verified'] ?? null) !== true) {
            $errors[] = 'distribution_evidence does not prove a verified candidate git archive';
        }
        $count = $dist['required_files_in_archive'] ?? null;
        if (! is_int($count) || $count < 1) {
            $errors[] = 'distribution_evidence required_files_in_archive is missing';
        }
        if (($dist['required_files_missing'] ?? null) !== []) {
            $errors[] = 'distribution_evidence reports required files missing from the archive';
        }
        if (($dist['archive_content_matches_tree'] ?? null) !== true) {
            $errors[] = 'distribution_evidence does not prove archive content equals the candidate tree';
        }
        if (($dist['forbidden_entries'] ?? null) !== []) {
            $errors[] = 'distribution_evidence reports forbidden archive entries';
        }
        foreach (['composer_archive_exclude', 'gitattributes_export_ignore'] as $key) {
            $status = is_array($dist[$key] ?? null) ? ($dist[$key]['status'] ?? null) : null;
            if (! in_array($status, ['NOT_APPLICABLE', 'APPLIED_NO_REQUIRED_IMPACT'], true)) {
                $errors[] = sprintf('distribution_evidence %s status is missing or reports required-content impact', $key);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $d
     * @return list<string>
     */
    private static function validateSourceOnly(array $d, string $target, string $candidate, string $channel, string $startedAt): array
    {
        $errors = [];
        foreach (self::SOURCE_ONLY_KEYS as $key) {
            if (! array_key_exists($key, $d)) {
                $errors[] = sprintf('source_only_decision is missing "%s"', $key);
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $str = static fn(string $k): string => is_string($d[$k]) ? $d[$k] : '';

        if (! (bool) preg_match('/^DEC-\d+$/', $str('decision_id')) || ! (bool) preg_match('#^docs/decisions/DEC-\d+[A-Za-z0-9_\-]*\.md$#', $str('decision_file'))) {
            $errors[] = 'source_only_decision has an invalid Decision ID or record path';
        }
        foreach (['decision_commit', 'record_blob_sha', 'index_blob_sha_at_qualification'] as $key) {
            if (! ReleaseContract::isSha($str($key))) {
                $errors[] = sprintf('source_only_decision "%s" is not a 40-hex object id', $key);
            }
        }
        if ($str('index_file') !== SourceOnlyDecisionVerifier::INDEX_PATH) {
            $errors[] = 'source_only_decision index_file is not the canonical Decision Index';
        }
        if ($str('status_at_qualification') !== 'ACTIVE' || $str('index_status_at_qualification') !== 'ACTIVE') {
            $errors[] = 'source_only_decision was not ACTIVE and indexed at qualification';
        }
        $owner = $d['owner_approval'];
        if (! is_array($owner) || ! (bool) preg_match('/\bOwner[- ]approved\b/i', is_string($owner['authority_statement'] ?? null) ? $owner['authority_statement'] : '') || ($owner['approving_authority'] ?? '') === '' || ($owner['approval_date'] ?? '') === '') {
            $errors[] = 'source_only_decision lacks complete Owner approval evidence';
        }
        $effective = ReleaseContract::parseTimestamp($str('effective_at'));
        $started = ReleaseContract::parseTimestamp($startedAt);
        if ($effective === null || $started === null || $effective >= $started) {
            $errors[] = 'source_only_decision effective_at does not predate qualification_started_at';
        }
        if ($str('package_name') !== ReleaseContract::PACKAGE_NAME) {
            $errors[] = 'source_only_decision package_name mismatch';
        }
        if (ReleaseContract::normalizeChannel($str('approved_distribution_channel')) !== ReleaseContract::normalizeChannel($channel)) {
            $errors[] = 'source_only_decision channel differs from approved_distribution_channel';
        }
        if (ReleaseContract::versionInScope($target, $str('version_scope')) !== true || $d['scope_covers_target'] !== true) {
            $errors[] = 'source_only_decision version scope does not cover the target';
        }
        if ($str('delivery_mode') !== 'source-only' || $d['intentional_canonical_delivery'] !== true) {
            $errors[] = 'source_only_decision does not declare intentional source-only canonical delivery';
        }
        if ($str('rationale') === '') {
            $errors[] = 'source_only_decision rationale is empty';
        }
        if ($str('qualification_target') !== $target || strtolower($str('candidate_sha')) !== strtolower($candidate) || $str('qualification_started_at') !== $startedAt) {
            $errors[] = 'source_only_decision qualification target/candidate/start do not match the evidence';
        }

        return $errors;
    }
}
